<?php

namespace Tests\Feature;

use App\Enums\CopyStatus;
use App\Enums\PageType;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\Project;
use App\Services\HistoryImport\FinalImageCopyParser;
use App\Services\HistoryImport\YujianHistoryImporter;
use App\Services\HistoryImport\YujianHistoryManifest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class YujianHistoryImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceRoot;

    private string $mappingPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceRoot = sys_get_temp_dir().'/qn-history-test-'.bin2hex(random_bytes(8));
        mkdir($this->sourceRoot);
        $this->mappingPath = $this->sourceRoot.'/d06.md';
        $this->makeSyntheticSources();
    }

    protected function tearDown(): void
    {
        if (isset($this->sourceRoot) && is_dir($this->sourceRoot)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->sourceRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->sourceRoot);
        }
        parent::tearDown();
    }

    private function importer(): YujianHistoryImporter
    {
        return app(YujianHistoryImporter::class);
    }

    private function plan(): array
    {
        return $this->importer()->prepare($this->sourceRoot, $this->mappingPath);
    }

    private function writeSource(string $relative, string $content): void
    {
        $path = $this->sourceRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function makeSyntheticSources(): void
    {
        foreach (YujianHistoryManifest::allPaths() as $path) {
            $this->writeSource(YujianHistoryManifest::currentSourcePath($path), 'synthetic source reference');
        }
        $mapping = [];
        $columnNames = collect(YujianHistoryManifest::columns())->pluck('name', 'slug');
        foreach (YujianHistoryManifest::items() as $index => $item) {
            $title = $item['title'];
            $types = YujianHistoryManifest::expectedTypes($item);
            $column = $columnNames[$item['column_slug']];
            $markdown = ["## 《{$title}》｜正式上图文案", '', '| 页 | 类型 | 标题／主文案 | 副标题／横线下小字 |', '| --- | --- | --- | --- |'];
            $mapping[] = '### 篇 '.($index + 1).'：《'.$title.'》';
            $mapping[] = '| page_no | page_type | column_label | cover_title | cover_subtitle | page_title | page_small_text | closing_line | note |';
            $mapping[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- |';
            foreach ($types as $pageIndex => $type) {
                $number = $pageIndex + 1;
                $pageNo = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                $copy = array_fill_keys(['column_label', 'cover_title', 'cover_subtitle', 'page_title', 'page_small_text', 'closing_line', 'note'], null);
                if ($type === PageType::Cover) {
                    $label = "封面，栏目「{$column}」";
                    $left = '“封面”／标题';
                    $right = '副标题';
                    $copy['column_label'] = $column;
                    $copy['cover_title'] = "“封面”\n标题";
                    $copy['cover_subtitle'] = $right;
                } elseif ($type === PageType::Content) {
                    $label = '正文';
                    $left = "第{$number}页标题";
                    $right = '小字／第二行';
                    $copy['page_title'] = $left;
                    $copy['page_small_text'] = "小字\n第二行";
                } elseif ($type === PageType::ColumnClosing) {
                    $label = '栏目收尾，历史图文版';
                    $left = '你在身边，／“我自己来”更有底气。';
                    $right = '—';
                    $copy['closing_line'] = "你在身边，\n“我自己来”更有底气。";
                } else {
                    $label = '固定封底';
                    $left = '调用用户原稿；无本篇新增文案';
                    $right = '—';
                }
                $markdown[] = "| {$pageNo} | {$label} | {$left} | {$right} |";
                $fields = array_map(fn ($field) => $copy[$field] === null ? 'null' : str_replace("\n", '\\n', $copy[$field]),
                    ['column_label', 'cover_title', 'cover_subtitle', 'page_title', 'page_small_text', 'closing_line', 'note']);
                $mapping[] = '| '.$number.' | '.$type->value.' | '.implode(' | ', $fields).' |';
            }
            $mapping[] = '';
            $this->writeSource(YujianHistoryManifest::currentSourcePath($item['final_path']), implode("\n", $markdown));
        }
        file_put_contents($this->mappingPath, implode("\n", $mapping));
    }

    public function test_preflight_is_read_only_and_checks_exact_source_counts(): void
    {
        $plan = $this->plan();
        $this->importer()->preflight($plan);
        $this->assertSame(11, $plan['source_files']);
        $this->assertSame(['cover' => 4, 'content' => 25, 'column_closing' => 4, 'fixed_back_cover' => 4], $plan['types']);
        $this->assertSame(0, DB::table('projects')->count());
        $this->assertSame(0, DB::table('content_pages')->count());
    }

    public function test_first_import_is_exact_and_second_import_is_no_op(): void
    {
        $plan = $this->plan();
        $this->assertSame('APPLIED', $this->importer()->apply($plan));
        $this->assertSame([1, 6, 4, 4, 37, 4, 37, 11], array_values($this->importer()->counts()));
        $this->assertSame('ALREADY_IMPORTED', $this->importer()->apply($plan));
        $this->assertSame([1, 6, 4, 4, 37, 4, 37, 11], array_values($this->importer()->counts()));
        $this->assertSame(0, DB::table('production_tasks')->count());
        $this->assertSame(0, DB::table('channel_tasks')->count());
        $this->assertSame(0, DB::table('content_page_versions')->whereNull('copy_revision_id')->count());
        $this->assertSame(37, DB::table('content_page_versions')->where('version_no', 1)->whereNotNull('page_no_snapshot')->whereNotNull('page_type_snapshot')->count());
        $this->assertSame(4, DB::table('content_items')->where('copy_status', CopyStatus::Confirmed->value)->count());
        $this->assertSame(4, DB::table('content_copy_revisions')->where('revision_no', 1)->count());
        foreach (YujianHistoryManifest::items() as $item) {
            $revision = ContentItem::query()->where('title', $item['title'])->firstOrFail()->contentCopyRevisions()->firstOrFail();
            $this->assertSame($item['confirmed_date'].' 00:00:00', $revision->confirmed_at->utc()->format('Y-m-d H:i:s'));
        }
        $this->assertSame("“封面”\n标题", DB::table('content_page_versions')->whereNotNull('cover_title')->value('cover_title'));
        $this->assertSame("你在身边，\n“我自己来”更有底气。", DB::table('content_page_versions')->whereNotNull('closing_line')->value('closing_line'));
    }

    public function test_existing_revision_with_not_started_copy_status_aborts_without_writes(): void
    {
        $plan = $this->plan();
        $this->assertSame('APPLIED', $this->importer()->apply($plan));
        $this->assertSame('ALREADY_IMPORTED', $this->importer()->apply($plan));

        $item = ContentItem::query()->where('title', YujianHistoryManifest::items()[0]['title'])->firstOrFail();
        $revisionCount = DB::table('content_copy_revisions')->count();
        $versionCount = DB::table('content_page_versions')->count();
        $formalCopy = DB::table('content_page_versions')->where('content_item_id', $item->id)
            ->whereNotNull('closing_line')->value('closing_line');

        DB::table('content_items')->where('id', $item->id)->update(['copy_status' => CopyStatus::NotStarted->value]);

        $aborted = false;
        try {
            $this->importer()->apply($plan);
        } catch (RuntimeException $exception) {
            $aborted = true;
            $this->assertStringContainsString('IMPORT_ABORT', $exception->getMessage());
            $this->assertStringContainsString('not_started', $exception->getMessage());
        }
        $this->assertTrue($aborted);

        $this->assertSame($revisionCount, DB::table('content_copy_revisions')->count());
        $this->assertSame($versionCount, DB::table('content_page_versions')->count());
        $this->assertSame($formalCopy, DB::table('content_page_versions')->where('content_item_id', $item->id)
            ->whereNotNull('closing_line')->value('closing_line'));
    }

    public function test_missing_source_and_d06_mismatch_abort_before_writes(): void
    {
        unlink($this->sourceRoot.'/'.YujianHistoryManifest::projectSources()[0][1]);
        try {
            $this->plan();
            $this->fail('Expected missing source to abort.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SOURCE_FILE_NOT_FOUND', $exception->getMessage());
        }
        $this->writeSource(YujianHistoryManifest::projectSources()[0][1], 'restored');
        file_put_contents($this->mappingPath, str_replace('“封面”\\n标题', 'wrong title', file_get_contents($this->mappingPath)));
        try {
            $this->plan();
            $this->fail('Expected D06 mismatch to abort.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('field=cover_title', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('projects')->count());
    }

    public function test_ambiguous_or_conflicting_existing_target_aborts(): void
    {
        Project::factory()->create(['slug' => YujianHistoryManifest::PROJECT_SLUG, 'name' => '冲突名称']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Project slug/name conflict');
        $this->importer()->preflight($this->plan());
    }

    public function test_existing_topic_in_wrong_column_and_unknown_draft_abort(): void
    {
        $project = Project::factory()->create(['slug' => YujianHistoryManifest::PROJECT_SLUG, 'name' => YujianHistoryManifest::PROJECT_NAME]);
        $wrongColumn = $project->contentColumns()->create(['name' => '其他栏目', 'slug' => 'other-column', 'sort_order' => 99]);
        $wrongColumn->topics()->forceCreate(['project_id' => $project->id, 'title' => YujianHistoryManifest::items()[0]['title']]);
        try {
            $this->importer()->preflight($this->plan());
            $this->fail('Expected wrong column to abort.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Topic identity conflict', $exception->getMessage());
        }
        $wrongColumn->topics()->delete();
        $column = $project->contentColumns()->create([
            'name' => YujianHistoryManifest::columns()[0]['name'],
            'slug' => YujianHistoryManifest::columns()[0]['slug'],
            'sort_order' => 1,
        ]);
        $topic = $column->topics()->forceCreate(['project_id' => $project->id, 'title' => YujianHistoryManifest::items()[0]['title']]);
        $item = $topic->contentItems()->forceCreate([
            'project_id' => $project->id, 'content_column_id' => $column->id,
            'title' => YujianHistoryManifest::items()[0]['title'], 'copy_status' => CopyStatus::NotStarted,
        ]);
        $item->contentPages()->forceCreate(['project_id' => $project->id, 'page_no' => 1, 'page_type' => PageType::Cover]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown draft/page data');
        $this->importer()->apply($this->plan());
    }

    public function test_revision_one_conflict_aborts_and_later_revisions_do_not_change_baseline(): void
    {
        $plan = $this->plan();
        $this->importer()->apply($plan);
        $item = ContentItem::query()->where('title', YujianHistoryManifest::items()[0]['title'])->firstOrFail();
        $item->contentCopyRevisions()->forceCreate([
            'project_id' => $item->project_id, 'revision_no' => 2, 'confirmed_at' => now(),
        ]);
        $this->assertSame('ALREADY_IMPORTED', $this->importer()->apply($plan));
        DB::table('content_page_versions')->where('copy_revision_id', $item->contentCopyRevisions()->where('revision_no', 1)->value('id'))->limit(1)->update(['cover_title' => 'tampered']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Revision 1 copy conflict');
        $this->importer()->apply($plan);
    }

    public function test_database_failure_rolls_back_whole_batch(): void
    {
        // 故障注入必须**方言中立**。
        //
        // 旧实现用的是 `CREATE TRIGGER … SELECT RAISE(ABORT, …)` —— 那是 SQLite 专属语法，
        // 拿到 MySQL 8.4 上直接 1064 语法错误，测试连注入都做不到，更谈不上验证回滚。
        //
        // 现在改为：在 Eloquent creating 钩子里执行一条**必然失败**的写入 ——
        // 向一张不存在的表插入。SQLite 报 "no such table"，MySQL 报 "Table … doesn't exist"，
        // 两者都抛真正的 QueryException。
        //
        // 注入点选 ContentPage：importer 的事务里，project / column / topic / item /
        // revision 都已经写完，正要写第一张 content_page 时才失败 —— 因此下面那条
        // "注入时 projects 已非空" 的断言能证明失败**发生在事务中途**，
        // 而不是第一句 SQL 之前（那样回滚证明不了任何东西）。
        $injected = false;
        $projectsBeforeInjection = 0;
        $eventName = 'eloquent.creating: '.ContentPage::class;

        Event::listen($eventName, function () use (&$injected, &$projectsBeforeInjection): void {
            if ($injected) {
                return;
            }

            $injected = true;
            $projectsBeforeInjection = DB::table('projects')->count();

            DB::statement('INSERT INTO qn_no_such_table_for_failure_injection (id) VALUES (1)');
        });

        try {
            $this->importer()->apply($this->plan());
            $this->fail('Expected transaction to fail.');
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression(
                '/no such table|doesn\'t exist|does not exist|unknown table/i',
                $exception->getMessage(),
            );
        } finally {
            Event::forget($eventName);
        }

        $this->assertTrue($injected, '故障注入必须真的发生过，否则这个用例什么都没验证。');
        $this->assertGreaterThan(
            0,
            $projectsBeforeInjection,
            '失败必须发生在事务中途：注入时 projects 表里已经有行，否则证明不了整批回滚。',
        );
        $this->assertSame(0, DB::table('projects')->count(), '整批回滚后 projects 必须为 0。');
        $this->assertSame(0, DB::table('content_copy_revisions')->count(), '整批回滚后 content_copy_revisions 必须为 0。');
    }

    public function test_parser_rejects_duplicate_page_and_unknown_structure(): void
    {
        $parser = app(FinalImageCopyParser::class);
        $title = '测试';
        $head = "## 《测试》｜正式上图文案\n| 页 | 类型 | 标题／主文案 | 副标题／横线下小字 |\n| --- | --- | --- | --- |\n";
        $row = '| 01 | 封面，栏目「测试栏目」 | “标题”／下一行 | — |';
        $pages = $parser->parse($head.$row, $title, '测试栏目', [PageType::Cover]);
        $this->assertSame("“标题”\n下一行", $pages[0]['copy']['cover_title']);
        $this->assertNull($pages[0]['copy']['cover_subtitle']);
        $this->expectException(RuntimeException::class);
        $parser->parse($head.$row."\n".$row, $title, '测试栏目', [PageType::Cover, PageType::Content]);
    }

    public function test_parser_rejects_missing_primary_copy_and_unknown_type(): void
    {
        $parser = app(FinalImageCopyParser::class);
        $head = "## 《测试》｜正式上图文案\n| 页 | 类型 | 标题／主文案 | 副标题／横线下小字 |\n| --- | --- | --- | --- |\n";
        foreach (['| 01 | 封面，栏目「测试栏目」 | — | — |', '| 01 | 未知类型 | 标题 | — |'] as $row) {
            try {
                $parser->parse($head.$row, '测试', '测试栏目', [PageType::Cover]);
                $this->fail('Expected invalid copy structure to abort.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('IMPORT_ABORT', $exception->getMessage());
            }
        }
    }
}
