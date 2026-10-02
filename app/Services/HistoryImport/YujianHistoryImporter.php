<?php

namespace App\Services\HistoryImport;

use App\Enums\CopyStatus;
use App\Enums\SourceRole;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SourceReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class YujianHistoryImporter
{
    private const COPY_FIELDS = [
        'column_label', 'cover_title', 'cover_subtitle',
        'page_title', 'page_small_text', 'closing_line', 'note',
    ];

    public function __construct(
        private readonly FinalImageCopyParser $parser,
        private readonly D06MappingVerifier $verifier,
    ) {}

    public function prepare(string $sourceRoot, ?string $mappingPath = null): array
    {
        $root = realpath($sourceRoot);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('IMPORT_ABORT: source-root does not exist.');
        }
        $paths = YujianHistoryManifest::allPaths();
        if (count($paths) !== 11) {
            throw new RuntimeException('IMPORT_ABORT: expected exactly 11 source references.');
        }
        foreach ($paths as $path) {
            $this->sourceFile($root, $path);
        }
        $mappingFile = $mappingPath ?? base_path('docs/DEV-D06_CONTENT_PAGE_MAPPING.md');
        $mapping = @file_get_contents($mappingFile);
        if ($mapping === false) {
            throw new RuntimeException('IMPORT_ABORT: D06 mapping file is unavailable.');
        }
        $columnNames = [];
        foreach (YujianHistoryManifest::columns() as $column) {
            $columnNames[$column['slug']] = $column['name'];
        }
        $items = [];
        $types = ['cover' => 0, 'content' => 0, 'column_closing' => 0, 'fixed_back_cover' => 0];
        foreach (YujianHistoryManifest::items() as $item) {
            $source = file_get_contents($this->sourceFile($root, $item['final_path']));
            $pages = $this->parser->parse(
                $source, $item['title'], $columnNames[$item['column_slug']],
                YujianHistoryManifest::expectedTypes($item),
            );
            $this->verifier->verify($mapping, $item['title'], $pages);
            foreach ($pages as $page) {
                $types[$page['page_type']->value]++;
            }
            $items[] = [...$item, 'pages' => $pages];
        }
        if (array_sum($types) !== 37 || $types !== [
            'cover' => 4, 'content' => 25, 'column_closing' => 4, 'fixed_back_cover' => 4,
        ]) {
            throw new RuntimeException('IMPORT_ABORT: 37-page PageType invariant failed.');
        }

        return ['items' => $items, 'types' => $types, 'source_files' => count($paths)];
    }

    private function sourceFile(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, '..') || preg_match('/^(?:[A-Za-z]:|[\\\\\/])/', $relative)) {
            throw new RuntimeException("IMPORT_ABORT: unsafe source path {$relative}.");
        }
        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($candidate);
        if ($resolved === false || ! is_file($resolved)) {
            throw new RuntimeException("IMPORT_ABORT: SOURCE_FILE_NOT_FOUND {$relative}.");
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with(mb_strtolower($resolved), mb_strtolower($prefix))) {
            throw new RuntimeException("IMPORT_ABORT: source path escapes source-root: {$relative}.");
        }

        return $resolved;
    }

    public function preflight(array $plan): void
    {
        $project = $this->one(Project::query()->where('slug', YujianHistoryManifest::PROJECT_SLUG)->get(), 'Project');
        if ($project === null) {
            return;
        }
        if ($project->name !== YujianHistoryManifest::PROJECT_NAME) {
            throw new RuntimeException('IMPORT_ABORT: Project slug/name conflict.');
        }
        $columns = [];
        foreach (YujianHistoryManifest::columns() as $expected) {
            $column = $this->one($project->contentColumns()->where('slug', $expected['slug'])->get(), 'Column '.$expected['slug']);
            if ($column !== null && ($column->name !== $expected['name'] || $column->sort_order !== $expected['sort_order'])) {
                throw new RuntimeException("IMPORT_ABORT: Column identity conflict {$expected['slug']}.");
            }
            if ($column === null && $project->contentColumns()->where('name', $expected['name'])->exists()) {
                throw new RuntimeException("IMPORT_ABORT: Column name conflict {$expected['name']}.");
            }
            $columns[$expected['slug']] = $column;
        }
        foreach ($plan['items'] as $expected) {
            $column = $columns[$expected['column_slug']];
            $topics = $project->topics()->where('title', $expected['title'])->get();
            if ($topics->count() > 1 || ($topics->count() === 1 && $topics->first()->content_column_id !== $column?->id)) {
                throw new RuntimeException("IMPORT_ABORT: Topic identity conflict or AMBIGUOUS {$expected['title']}.");
            }
            $items = $project->contentItems()->where('title', $expected['title'])->get();
            if ($items->count() > 1 || ($items->count() === 1 && (
                $topics->count() !== 1 ||
                $items->first()->topic_id !== $topics->first()->id ||
                $items->first()->content_column_id !== $column?->id
            ))) {
                throw new RuntimeException("IMPORT_ABORT: ContentItem identity conflict or AMBIGUOUS {$expected['title']}.");
            }
            if ($items->count() === 1) {
                $this->verifyExistingItem($items->first(), $expected);
            }
        }
        foreach (YujianHistoryManifest::projectSources() as [$role, $path]) {
            $this->verifySource($project->id, null, $role, $path);
        }
        foreach ($plan['items'] as $expected) {
            $item = $project->contentItems()->where('title', $expected['title'])->first();
            if ($item !== null) {
                $this->verifySource($project->id, $item->id, SourceRole::FinalImageCopy, $expected['final_path']);
                $this->verifySource($project->id, $item->id, SourceRole::SourceScript, $expected['script_path']);
            }
        }
    }

    private function one($models, string $name): mixed
    {
        if ($models->count() > 1) {
            throw new RuntimeException("IMPORT_ABORT: AMBIGUOUS {$name}.");
        }

        return $models->first();
    }

    private function verifyExistingItem(ContentItem $item, array $expected): void
    {
        $revisions = $item->contentCopyRevisions()->orderBy('revision_no')->get();
        if ($revisions->isEmpty()) {
            if ($item->contentPages()->exists() || $item->copy_status !== CopyStatus::NotStarted) {
                throw new RuntimeException("IMPORT_ABORT: unknown draft/page data in {$expected['title']}.");
            }

            return;
        }
        if ($item->copy_status === CopyStatus::NotStarted) {
            throw new RuntimeException("IMPORT_ABORT: ContentItem {$expected['title']} has a formal revision but copy_status is not_started.");
        }
        $baseline = $revisions->first();
        if ($baseline->revision_no !== 1) {
            throw new RuntimeException("IMPORT_ABORT: missing Revision 1 in {$expected['title']}.");
        }
        $date = $expected['confirmed_date'].' 00:00:00';
        if ($baseline->confirmed_at?->utc()->format('Y-m-d H:i:s') !== $date) {
            throw new RuntimeException("IMPORT_ABORT: historical confirmed_at conflict in {$expected['title']}.");
        }
        $snapshots = $baseline->pageVersions()->orderBy('page_no_snapshot')->get();
        if ($snapshots->count() !== count($expected['pages'])) {
            throw new RuntimeException("IMPORT_ABORT: Revision 1 page count conflict in {$expected['title']}.");
        }
        foreach ($expected['pages'] as $index => $page) {
            $snapshot = $snapshots[$index];
            if ($snapshot->project_id !== $item->project_id ||
                $snapshot->content_item_id !== $item->id ||
                $snapshot->page_no_snapshot !== $page['page_no'] ||
                $snapshot->page_type_snapshot !== $page['page_type']->value ||
                $snapshot->version_no !== 1 ||
                ! $item->contentPages()->whereKey($snapshot->content_page_id)->exists()) {
                throw new RuntimeException("IMPORT_ABORT: Revision 1 structure conflict in {$expected['title']} page {$page['page_no']}.");
            }
            foreach (self::COPY_FIELDS as $field) {
                if ($page['copy'][$field] !== $snapshot->$field) {
                    throw new RuntimeException("IMPORT_ABORT: Revision 1 copy conflict item={$expected['title']} page_no={$page['page_no']} field={$field}.");
                }
            }
        }
    }

    private function verifySource(int $projectId, ?int $itemId, SourceRole $role, string $path): bool
    {
        $query = SourceReference::query()->where('project_id', $projectId)
            ->where('role', $role->value)->where('source_path', $path);
        $itemId === null ? $query->whereNull('content_item_id') : $query->where('content_item_id', $itemId);
        $matches = $query->get();
        if ($matches->count() > 1) {
            throw new RuntimeException("IMPORT_ABORT: duplicate SourceReference {$role->value} {$path}.");
        }
        if ($matches->count() === 1 && $matches->first()->authority !== $role->defaultAuthority()) {
            throw new RuntimeException("IMPORT_ABORT: SourceReference authority conflict {$role->value} {$path}.");
        }

        return $matches->count() === 1;
    }

    public function apply(array $plan): string
    {
        return DB::transaction(function () use ($plan): string {
            $this->preflight($plan);
            $productionBefore = DB::table('production_tasks')->count();
            $channelBefore = DB::table('channel_tasks')->count();
            $changed = false;
            $project = Project::query()->where('slug', YujianHistoryManifest::PROJECT_SLUG)->first();
            if ($project === null) {
                $project = Project::query()->create([
                    'name' => YujianHistoryManifest::PROJECT_NAME,
                    'slug' => YujianHistoryManifest::PROJECT_SLUG,
                    'description' => null,
                ]);
                $changed = true;
            }
            $columns = [];
            foreach (YujianHistoryManifest::columns() as $expected) {
                $column = $project->contentColumns()->where('slug', $expected['slug'])->first();
                if ($column === null) {
                    $column = $project->contentColumns()->create([
                        ...$expected, 'description' => null,
                    ]);
                    $changed = true;
                }
                $columns[$expected['slug']] = $column;
            }
            foreach ($plan['items'] as $expected) {
                $column = $columns[$expected['column_slug']];
                $topic = $column->topics()->where('title', $expected['title'])->first();
                if ($topic === null) {
                    $topic = $column->topics()->forceCreate([
                        'project_id' => $project->id,
                        'title' => $expected['title'],
                        'description' => null,
                    ]);
                    $changed = true;
                }
                $item = $topic->contentItems()->where('title', $expected['title'])->first();
                if ($item === null) {
                    $item = $topic->contentItems()->forceCreate([
                        'project_id' => $project->id,
                        'content_column_id' => $column->id,
                        'title' => $expected['title'],
                        'copy_status' => CopyStatus::NotStarted,
                    ]);
                    $changed = true;
                }
                if (! $item->contentCopyRevisions()->where('revision_no', 1)->exists()) {
                    $revision = $item->contentCopyRevisions()->forceCreate([
                        'project_id' => $project->id,
                        'revision_no' => 1,
                        'confirmed_at' => CarbonImmutable::parse($expected['confirmed_date'].' 00:00:00', 'UTC'),
                    ]);
                    foreach ($expected['pages'] as $pageData) {
                        $page = $item->contentPages()->forceCreate([
                            'project_id' => $project->id,
                            'page_no' => $pageData['page_no'],
                            'page_type' => $pageData['page_type'],
                        ]);
                        $page->versions()->forceCreate([
                            'project_id' => $project->id,
                            'content_item_id' => $item->id,
                            'copy_revision_id' => $revision->id,
                            'version_no' => 1,
                            'page_no_snapshot' => $pageData['page_no'],
                            'page_type_snapshot' => $pageData['page_type']->value,
                            ...$pageData['copy'],
                        ]);
                    }
                    $item->update(['copy_status' => CopyStatus::Confirmed]);
                    $changed = true;
                }
                $changed = $this->ensureSource($project->id, $item->id, SourceRole::FinalImageCopy, $expected['final_path']) || $changed;
                $changed = $this->ensureSource($project->id, $item->id, SourceRole::SourceScript, $expected['script_path']) || $changed;
            }
            foreach (YujianHistoryManifest::projectSources() as [$role, $path]) {
                $changed = $this->ensureSource($project->id, null, $role, $path) || $changed;
            }
            $this->preflight($plan);
            if (DB::table('production_tasks')->count() !== $productionBefore ||
                DB::table('channel_tasks')->count() !== $channelBefore) {
                throw new RuntimeException('IMPORT_ABORT: production/channel tasks changed.');
            }

            return $changed ? 'APPLIED' : 'ALREADY_IMPORTED';
        }, 3);
    }

    private function ensureSource(int $projectId, ?int $itemId, SourceRole $role, string $path): bool
    {
        if ($this->verifySource($projectId, $itemId, $role, $path)) {
            return false;
        }
        SourceReference::query()->create([
            'project_id' => $projectId,
            'content_item_id' => $itemId,
            'role' => $role,
            'authority' => $role->defaultAuthority(),
            'source_path' => $path,
            'note' => null,
        ]);

        return true;
    }

    public function counts(): array
    {
        $project = Project::query()->where('slug', YujianHistoryManifest::PROJECT_SLUG)->first();
        if ($project === null) {
            return array_fill_keys([
                'projects', 'content_columns', 'topics', 'content_items', 'content_pages',
                'content_copy_revisions', 'content_page_versions', 'source_references',
            ], 0);
        }

        return [
            'projects' => 1,
            'content_columns' => $project->contentColumns()->count(),
            'topics' => $project->topics()->count(),
            'content_items' => $project->contentItems()->count(),
            'content_pages' => DB::table('content_pages')->where('project_id', $project->id)->count(),
            'content_copy_revisions' => DB::table('content_copy_revisions')->where('project_id', $project->id)->count(),
            'content_page_versions' => DB::table('content_page_versions')->where('project_id', $project->id)->count(),
            'source_references' => $project->sourceReferences()->count(),
        ];
    }
}
