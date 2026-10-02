<?php

namespace App\Http\Controllers;

use App\Enums\CopyStatus;
use App\Http\Requests\AppendPageDraftRequest;
use App\Http\Requests\ReorderPagesRequest;
use App\Http\Requests\StoreContentPageRequest;
use App\Http\Requests\UpdateContentPageRequest;
use App\Http\Resources\ContentCopyRevisionResource;
use App\Http\Resources\ContentPageResource;
use App\Http\Resources\ContentPageVersionResource;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use App\Models\Project;
use App\Services\ContentCopyService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ContentPageController extends Controller
{
    private function item(Request $request, Project $project, int $column, int $topic, int $item): ContentItem
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return $project->contentColumns()->findOrFail($column)
            ->topics()->findOrFail($topic)
            ->contentItems()->findOrFail($item);
    }

    private function page(ContentItem $item, int $page): ContentPage
    {
        return $item->contentPages()->findOrFail($page);
    }

    private function withLatestVersions(Collection $pages): Collection
    {
        $latest = ContentPageVersion::query()
            ->whereIn('content_page_id', $pages->pluck('id'))
            ->orderByDesc('version_no')->get()->unique('content_page_id')->keyBy('content_page_id');

        return $pages->each(fn (ContentPage $page) => $page->setRelation('latestVersion', $latest->get($page->id)));
    }

    public function index(Request $request, Project $project, int $column, int $topic, int $item): AnonymousResourceCollection
    {
        $model = $this->item($request, $project, $column, $topic, $item);
        $pages = $this->withLatestVersions($model->contentPages()->orderBy('page_no')->get());

        return ContentPageResource::collection($pages);
    }

    public function store(StoreContentPageRequest $request, Project $project, int $column, int $topic, int $item): ContentPageResource
    {
        $model = $this->item($request, $project, $column, $topic, $item);
        $data = $request->validated();
        if ($model->contentPages()->where('page_no', $data['page_no'])->exists()) {
            throw ValidationException::withMessages(['page_no' => 'Page number already exists in this ContentItem.']);
        }
        $page = $model->contentPages()->forceCreate([
            'project_id' => $project->id,
            ...$data,
        ]);
        if ($model->copy_status === CopyStatus::Confirmed) {
            $model->update(['copy_status' => CopyStatus::Editing]);
        }
        $page->setRelation('latestVersion', null);

        return new ContentPageResource($page);
    }

    public function show(Request $request, Project $project, int $column, int $topic, int $item, int $page): ContentPageResource
    {
        $model = $this->item($request, $project, $column, $topic, $item);
        $found = $this->page($model, $page);
        $this->withLatestVersions(collect([$found]));

        return new ContentPageResource($found);
    }

    public function update(UpdateContentPageRequest $request, Project $project, int $column, int $topic, int $item, int $page): ContentPageResource
    {
        $model = $this->item($request, $project, $column, $topic, $item);
        $found = $this->page($model, $page);
        $newType = $request->validated('page_type');
        if ($found->page_type->value !== $newType) {
            $found->update(['page_type' => $newType]);
            if ($model->copy_status === CopyStatus::Confirmed) {
                $model->update(['copy_status' => CopyStatus::Editing]);
            }
        }
        $this->withLatestVersions(collect([$found]));

        return new ContentPageResource($found);
    }

    public function appendDraft(AppendPageDraftRequest $request, Project $project, int $column, int $topic, int $item, int $page, ContentCopyService $service): ContentPageVersionResource
    {
        $model = $this->item($request, $project, $column, $topic, $item);

        return new ContentPageVersionResource($service->appendDraft($this->page($model, $page), $request->validated()));
    }

    public function reorder(ReorderPagesRequest $request, Project $project, int $column, int $topic, int $item, ContentCopyService $service): AnonymousResourceCollection
    {
        $model = $this->item($request, $project, $column, $topic, $item);
        try {
            $service->reorderPages($model, $request->validated('page_ids'));
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['page_ids' => $error->getMessage()]);
        }

        return ContentPageResource::collection(
            $this->withLatestVersions($model->contentPages()->orderBy('page_no')->get())
        );
    }

    public function confirm(Request $request, Project $project, int $column, int $topic, int $item, ContentCopyService $service): ContentCopyRevisionResource
    {
        $model = $this->item($request, $project, $column, $topic, $item);
        try {
            $revision = $service->confirmContentItem($model);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['copy' => $error->getMessage()]);
        }

        return new ContentCopyRevisionResource($revision->load([
            'pageVersions' => fn ($query) => $query->orderBy('page_no_snapshot'),
        ]));
    }

    public function revisions(Request $request, Project $project, int $column, int $topic, int $item): AnonymousResourceCollection
    {
        $model = $this->item($request, $project, $column, $topic, $item);

        return ContentCopyRevisionResource::collection(
            $model->contentCopyRevisions()->orderByDesc('revision_no')->get()
        );
    }

    public function revision(Request $request, Project $project, int $column, int $topic, int $item, int $revision): ContentCopyRevisionResource
    {
        $model = $this->item($request, $project, $column, $topic, $item);
        $found = $model->contentCopyRevisions()->findOrFail($revision);

        return new ContentCopyRevisionResource($found->load([
            'pageVersions' => fn ($query) => $query->orderBy('page_no_snapshot'),
        ]));
    }

    public function current(Request $request, Project $project, int $column, int $topic, int $item): ContentCopyRevisionResource|JsonResponse
    {
        $model = $this->item($request, $project, $column, $topic, $item);
        $revision = $model->contentCopyRevisions()->orderByDesc('revision_no')->first();
        if ($revision === null) {
            return response()->json(['data' => null]);
        }

        return new ContentCopyRevisionResource($revision->load([
            'pageVersions' => fn ($query) => $query->orderBy('page_no_snapshot'),
        ]));
    }

    public function working(Request $request, Project $project, int $column, int $topic, int $item): AnonymousResourceCollection
    {
        $model = $this->item($request, $project, $column, $topic, $item);

        return ContentPageResource::collection(
            $this->withLatestVersions($model->contentPages()->orderBy('page_no')->get())
        );
    }
}
