<?php

namespace Tests\Feature;

use App\Enums\CopyStatus;
use App\Enums\PageType;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use App\Models\Project;
use App\Services\ContentCopyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class ContentCopyCoreTest extends TestCase
{
    use RefreshDatabase;

    private function page(ContentItem $item, int $number, PageType $type = PageType::Content): ContentPage
    {
        return ContentPage::factory()->create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'page_no' => $number,
            'page_type' => $type,
        ]);
    }

    private function rejects(callable $action, string $exception): void
    {
        try {
            $action();
            $this->fail("Expected {$exception}");
        } catch (AssertionFailedError $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }
    }

    public function test_pages_and_revisions_have_correct_relations_and_enum_cast(): void
    {
        $item = ContentItem::factory()->create();
        $page = $this->page($item, 1, PageType::Cover);
        $revision = ContentCopyRevision::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id]);
        $version = ContentPageVersion::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
            'content_page_id' => $page->id, 'copy_revision_id' => $revision->id,
        ]);
        $this->assertSame(PageType::Cover, $page->page_type);
        $this->assertSame('cover', $page->getRawOriginal('page_type'));
        $this->assertSame($item->id, $page->contentItem->id);
        $this->assertSame($page->id, $item->contentPages->first()->id);
        $this->assertSame($revision->id, $item->contentCopyRevisions->first()->id);
        $this->assertSame($version->id, $page->versions->first()->id);
        $this->assertSame($version->id, $revision->pageVersions->first()->id);
        $this->assertSame($item->id, $version->contentItem->id);
        $this->assertSame($revision->id, $version->contentCopyRevision->id);
    }

    public function test_page_project_and_page_number_constraints(): void
    {
        $first = ContentItem::factory()->create();
        $second = ContentItem::factory()->create();
        $this->page($first, 1);
        $this->page($second, 1);
        $this->assertDatabaseCount('content_pages', 2);
        $this->rejects(fn () => $this->page($first, 1), QueryException::class);
        $this->rejects(fn () => ContentPage::factory()->create([
            'project_id' => $second->project_id, 'content_item_id' => $first->id, 'page_no' => 2,
        ]), QueryException::class);
    }

    public function test_version_composite_foreign_keys_and_nullable_unique(): void
    {
        $item = ContentItem::factory()->create();
        $other = ContentItem::factory()->create();
        $page = $this->page($item, 1);
        $revision = ContentCopyRevision::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id]);
        $foreign = ContentCopyRevision::factory()->create(['project_id' => $other->project_id, 'content_item_id' => $other->id]);
        $base = ['project_id' => $item->project_id, 'content_item_id' => $item->id, 'content_page_id' => $page->id];
        ContentPageVersion::factory()->create([...$base, 'version_no' => 1, 'copy_revision_id' => null]);
        ContentPageVersion::factory()->create([...$base, 'version_no' => 2, 'copy_revision_id' => null]);
        ContentPageVersion::factory()->create([...$base, 'version_no' => 3, 'copy_revision_id' => $revision->id]);
        $this->rejects(fn () => ContentPageVersion::factory()->create([...$base, 'version_no' => 4, 'copy_revision_id' => $revision->id]), QueryException::class);
        $this->rejects(fn () => ContentPageVersion::factory()->create([...$base, 'version_no' => 3]), QueryException::class);
        $this->rejects(fn () => ContentPageVersion::factory()->create([...$base, 'version_no' => 4, 'copy_revision_id' => $foreign->id]), QueryException::class);
        $this->rejects(fn () => ContentPageVersion::factory()->create([
            ...$base, 'project_id' => $other->project_id, 'version_no' => 4,
        ]), QueryException::class);
        $this->assertDatabaseCount('content_page_versions', 3);
    }

    public function test_append_draft_adds_versions_and_reopens_confirmed_copy(): void
    {
        $item = ContentItem::factory()->create();
        $page = $this->page($item, 1);
        $service = app(ContentCopyService::class);
        $first = $service->appendDraft($page, ['page_title' => '第一稿']);
        $this->rejects(fn () => $first->update(['page_title' => '覆盖旧草稿']), LogicException::class);
        $second = $service->appendDraft($page, ['page_title' => '第二稿']);
        $this->assertSame(1, $first->version_no);
        $this->assertSame(2, $second->version_no);
        $this->assertSame('第一稿', $first->fresh()->page_title);
        $this->assertNull($second->copy_revision_id);
        $this->assertSame(CopyStatus::Editing, $item->refresh()->copy_status);
        $service->confirmContentItem($item);
        $third = $service->appendDraft($page, ['page_title' => '确认后编辑']);
        $this->assertSame(4, $third->version_no);
        $this->assertSame(CopyStatus::Editing, $item->refresh()->copy_status);
        $this->assertSame('第二稿', $second->fresh()->page_title);
    }

    public function test_full_confirmations_preserve_old_versions_and_unmodified_pages(): void
    {
        $item = ContentItem::factory()->create();
        $first = $this->page($item, 1, PageType::Cover);
        $second = $this->page($item, 2);
        $service = app(ContentCopyService::class);
        $service->appendDraft($first, ['cover_title' => '初版封面']);
        $service->appendDraft($second, ['page_title' => '正文']);
        $one = $service->confirmContentItem($item);
        $this->assertSame(1, $one->revision_no);
        $this->assertSame(2, $one->pageVersions()->count());
        $this->assertSame(CopyStatus::Confirmed, $item->refresh()->copy_status);
        $this->assertSame($one->id, $item->contentCopyRevisions()->orderByDesc('revision_no')->first()->id);
        $service->appendDraft($first, ['cover_title' => '新版封面']);
        $two = $service->confirmContentItem($item);
        $this->assertSame(2, $two->revision_no);
        $this->assertSame(2, $two->pageVersions()->count());
        $this->assertSame('初版封面', $one->pageVersions()->where('content_page_id', $first->id)->first()->cover_title);
        $this->assertSame('新版封面', $two->pageVersions()->where('content_page_id', $first->id)->first()->cover_title);
        $this->assertSame('正文', $two->pageVersions()->where('content_page_id', $second->id)->first()->page_title);
        $this->assertSame(1, $two->pageVersions()->where('content_page_id', $first->id)->first()->page_no_snapshot);
    }

    public function test_confirmation_rolls_back_when_a_page_has_no_version_or_invalid_copy(): void
    {
        $item = ContentItem::factory()->create();
        $first = $this->page($item, 1);
        $second = $this->page($item, 2);
        $service = app(ContentCopyService::class);
        $service->appendDraft($first, ['page_title' => '可确认']);
        $this->rejects(fn () => $service->confirmContentItem($item), InvalidArgumentException::class);
        $this->assertDatabaseCount('content_copy_revisions', 0);
        $this->assertDatabaseCount('content_page_versions', 1);
        $service->appendDraft($second, ['page_title' => '']);
        $this->rejects(fn () => $service->confirmContentItem($item), InvalidArgumentException::class);
        $this->assertDatabaseCount('content_copy_revisions', 0);
        $this->assertDatabaseCount('content_page_versions', 2);
    }

    public function test_formal_versions_cannot_be_updated_demoted_or_deleted(): void
    {
        $item = ContentItem::factory()->create();
        $page = $this->page($item, 1);
        $service = app(ContentCopyService::class);
        $service->appendDraft($page, ['page_title' => '正式文字']);
        $revision = $service->confirmContentItem($item);
        $formal = $revision->pageVersions()->first();
        $this->rejects(fn () => $formal->update(['page_title' => '覆盖']), LogicException::class);
        $this->rejects(fn () => $formal->update(['copy_revision_id' => null]), LogicException::class);
        $this->rejects(fn () => $formal->delete(), LogicException::class);
        $this->assertSame('正式文字', $formal->fresh()->page_title);
        $this->assertSame($revision->id, $formal->fresh()->copy_revision_id);
    }

    public function test_confirmed_revision_cannot_be_updated_or_deleted(): void
    {
        $item = ContentItem::factory()->create();
        $page = $this->page($item, 1);
        $service = app(ContentCopyService::class);
        $service->appendDraft($page, ['page_title' => '正式文字']);
        $revision = $service->confirmContentItem($item);
        $this->assertSame(1, $revision->revision_no);
        $originalConfirmedAt = $revision->getRawOriginal('confirmed_at');

        $this->rejects(fn () => $revision->update(['revision_no' => 99]), LogicException::class);
        $this->assertSame(1, $revision->fresh()->revision_no);

        $this->rejects(fn () => $revision->fresh()->update(['confirmed_at' => now()->addDay()]), LogicException::class);
        $this->assertSame($originalConfirmedAt, $revision->fresh()->getRawOriginal('confirmed_at'));

        $this->rejects(fn () => $revision->fresh()->delete(), LogicException::class);
        $this->assertDatabaseHas('content_copy_revisions', ['id' => $revision->id]);
    }

    public function test_reorder_is_complete_safe_and_preserves_historical_snapshot(): void
    {
        $item = ContentItem::factory()->create();
        $pages = collect([1, 2, 3])->map(fn (int $no) => $this->page($item, $no));
        $service = app(ContentCopyService::class);
        foreach ($pages as $page) {
            $service->appendDraft($page, ['page_title' => "页{$page->page_no}"]);
        }
        $revision = $service->confirmContentItem($item);
        $ids = [$pages[2]->id, $pages[0]->id, $pages[1]->id];
        $service->reorderPages($item, $ids);
        $this->assertSame($ids, $item->contentPages()->orderBy('page_no')->pluck('id')->all());
        $this->assertSame([1, 2, 3], $item->contentPages()->orderBy('page_no')->pluck('page_no')->all());
        $this->assertSame([1, 2, 3], $revision->pageVersions()->orderBy('page_no_snapshot')->pluck('page_no_snapshot')->all());
        $this->assertSame($pages[0]->id, $revision->pageVersions()->where('page_no_snapshot', 1)->first()->content_page_id);
        $this->rejects(fn () => $service->reorderPages($item, [$pages[0]->id, $pages[0]->id, $pages[1]->id]), InvalidArgumentException::class);
        $this->rejects(fn () => $service->reorderPages($item, [$pages[0]->id, $pages[1]->id]), InvalidArgumentException::class);
        $foreign = $this->page(ContentItem::factory()->create(), 1);
        $this->rejects(fn () => $service->reorderPages($item, [$pages[0]->id, $pages[1]->id, $foreign->id]), InvalidArgumentException::class);
    }

    public function test_page_counts_support_legacy_shapes_without_importing_history(): void
    {
        foreach ([10, 9, 10, 8] as $count) {
            $item = ContentItem::factory()->create();
            foreach (range(1, $count) as $no) {
                $this->page($item, $no);
            }
            $this->assertSame($count, $item->contentPages()->count());
        }
        $this->assertDatabaseCount('content_pages', 37);
    }

    public function test_revision_from_another_item_in_the_same_project_is_rejected(): void
    {
        $project = Project::factory()->create();
        $first = ContentItem::factory()->for($project)->create();
        $second = ContentItem::factory()->for($project)->create();
        $page = $this->page($first, 1);
        $revision = ContentCopyRevision::factory()->create([
            'project_id' => $project->id, 'content_item_id' => $second->id,
        ]);
        $this->rejects(fn () => ContentPageVersion::factory()->create([
            'project_id' => $project->id, 'content_item_id' => $first->id,
            'content_page_id' => $page->id, 'copy_revision_id' => $revision->id,
        ]), QueryException::class);
    }

    public function test_factory_defaults_keep_all_ancestors_in_one_project(): void
    {
        $page = ContentPage::factory()->create();
        $revision = ContentCopyRevision::factory()->create();
        $version = ContentPageVersion::factory()->create();
        $this->assertSame($page->project_id, $page->contentItem->project_id);
        $this->assertSame($revision->project_id, $revision->contentItem->project_id);
        $this->assertSame($version->project_id, $version->contentPage->project_id);
        $this->assertSame($version->content_item_id, $version->contentPage->content_item_id);
    }

    public function test_confirmation_rejects_invalid_type_and_keeps_prior_formal_snapshot(): void
    {
        $item = ContentItem::factory()->create();
        $page = $this->page($item, 1);
        $service = app(ContentCopyService::class);
        $service->appendDraft($page, ['page_title' => '旧正式稿']);
        $first = $service->confirmContentItem($item);
        $service->appendDraft($page, ['page_title' => '新工作稿']);
        DB::table('content_pages')->where('id', $page->id)->update(['page_type' => 'invalid']);
        $this->rejects(fn () => $service->confirmContentItem($item), InvalidArgumentException::class);
        $this->assertDatabaseCount('content_copy_revisions', 1);
        $this->assertSame('旧正式稿', $first->pageVersions()->first()->page_title);
        $this->assertSame(CopyStatus::Editing, $item->refresh()->copy_status);
    }

    public function test_revision_number_is_unique_per_item_and_page_number_is_positive(): void
    {
        $item = ContentItem::factory()->create();
        ContentCopyRevision::factory()->create(['project_id' => $item->project_id, 'content_item_id' => $item->id]);
        $this->rejects(fn () => ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
        ]), QueryException::class);
        $this->rejects(fn () => $this->page($item, 0), InvalidArgumentException::class);
    }

    public function test_all_page_types_confirm_and_fixed_back_cover_needs_no_copy(): void
    {
        $item = ContentItem::factory()->create();
        $service = app(ContentCopyService::class);
        $cover = $this->page($item, 1, PageType::Cover);
        $content = $this->page($item, 2, PageType::Content);
        $closing = $this->page($item, 3, PageType::ColumnClosing);
        $back = $this->page($item, 4, PageType::FixedBackCover);
        $service->appendDraft($cover, ['cover_title' => '封面']);
        $service->appendDraft($content, ['page_small_text' => '正文小字']);
        $service->appendDraft($closing, ['closing_line' => '收尾']);
        $service->appendDraft($back, []);
        $revision = $service->confirmContentItem($item);
        $this->assertSame(4, $revision->pageVersions()->count());
        $this->assertNull($revision->pageVersions()->where('content_page_id', $back->id)->first()->page_title);
        $this->assertSame('fixed_back_cover', $revision->pageVersions()->where('content_page_id', $back->id)->first()->page_type_snapshot);
    }
}
