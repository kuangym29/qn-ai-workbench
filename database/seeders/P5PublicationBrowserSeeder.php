<?php

namespace Database\Seeders;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Models\VideoContent;
use App\Services\V2\ContentVersionResolver;
use Illuminate\Database\Seeder;

/**
 * P5-I01 浏览器验收专用演示数据（不挂进 DatabaseSeeder，不在生产逻辑中使用）。
 *
 * 为三个可发布目标各构建一个「已审核通过」分支，使「标记已发布」闭环可在真实浏览器中走通：
 *   - image_post_copy   → channel=wechat_official + format=image_post + 3 个内容页
 *   - article_copy      → channel=wechat_official + format=article + 一篇图文
 *   - video_production  → channel=wechat_channels + format=video + 一份视频制作
 *
 * 每个分支的 ReviewRequest 用 ContentVersionResolver 计算真实 version_label 后直接置为
 * approved —— 这样浏览器里点「标记已发布」时，PublicationService 能查到 exact approved review。
 * 不预置任何 PublicationRecord，保留「首次发布 → 创建」与「修改后重新发布 → 第二条」的验证空间。
 *
 * 登录用户：p5@example.test / password
 * 项目：p5-pub-demo
 */
class P5PublicationBrowserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'p5@example.test'],
            ['name' => 'P5 Browser', 'password' => bcrypt('password')],
        );

        $project = Project::query()->firstOrCreate(
            ['slug' => 'p5-pub-demo'],
            ['name' => '青柠育见 · P5 发布验收', 'description' => 'P5-I01 发布闭环浏览器验收演示项目'],
        );

        // ── 1. image_post_copy ──────────────────────────────────────────
        $ipItem = ContentItem::factory()->create([
            'project_id' => $project->id,
            'title' => '柠檬熊的一天（图片文章）',
        ]);
        $ipBranch = ContentBranch::factory()->for($ipItem)->create([
            'project_id' => $project->id,
            'channel' => Channel::WechatOfficial->value,
            'format' => ContentFormat::ImagePost->value,
        ]);
        foreach ([1 => '柠檬熊在客厅给爸爸做早餐', 2 => '妈妈带柠檬熊去公园放风筝', 3 => '一家人围坐在客厅吃晚饭'] as $no => $copy) {
            ContentPage::factory()->for($ipItem)->create([
                'project_id' => $project->id,
                'page_no' => $no,
                'page_type' => 'content',
                'final_copy' => $copy,
                'visual_evidence' => '暖光场景',
            ]);
        }
        $this->approve($project, $ipItem, $ipBranch, ReviewTargetType::ImagePostCopy);

        // ── 2. article_copy ─────────────────────────────────────────────
        $arItem = ContentItem::factory()->create([
            'project_id' => $project->id,
            'title' => '秋日育儿指南（图文）',
        ]);
        $arBranch = ContentBranch::factory()->for($arItem)->create([
            'project_id' => $project->id,
            'channel' => Channel::WechatOfficial->value,
            'format' => ContentFormat::Article->value,
        ]);
        ArticleContent::factory()->create([
            'project_id' => $project->id,
            'content_item_id' => $arItem->id,
            'content_branch_id' => $arBranch->id,
            'headline' => '秋日育儿指南',
            'summary' => '换季护理要点一览',
            'body' => '秋天气温变化大，注意给孩子适时添减衣物，保持室内湿度，预防呼吸道不适。',
            'image_suggestions' => [],
            'cover_suggestion' => '暖色秋景封面',
        ]);
        $this->approve($project, $arItem, $arBranch, ReviewTargetType::ArticleCopy);

        // ── 3. video_production ────────────────────────────────────────
        $viItem = ContentItem::factory()->create([
            'project_id' => $project->id,
            'title' => '柠檬熊早餐视频（视频制作）',
        ]);
        $viBranch = ContentBranch::factory()->for($viItem)->create([
            'project_id' => $project->id,
            'channel' => Channel::WechatChannels->value,
            'format' => ContentFormat::Video->value,
        ]);
        VideoContent::factory()->create([
            'project_id' => $project->id,
            'content_item_id' => $viItem->id,
            'content_branch_id' => $viBranch->id,
            'batch_instruction' => '暖调家庭日常',
            'narration' => '旁白：柠檬熊的早晨',
            'units' => [['source_page_id' => 1, 'dynamic_instruction' => '镜头缓缓推进', 'duration_seconds' => 5.0, 'note' => null]],
            'source_fingerprint' => null,
        ]);
        $this->approve($project, $viItem, $viBranch, ReviewTargetType::VideoProduction);

        // 让登录用户持有该项目（ProjectContext 由点击「进入」设置；这里仅确保项目可被发现）。
        unset($user, $ipItem, $ipBranch, $arItem, $arBranch, $viItem, $viBranch);
    }

    /**
     * 用与提交审核完全相同的 ContentVersionResolver 计算 version_label，再落地一份 approved
     * ReviewRequest —— 保证 PublicationService 能查到 exact approved review。
     */
    private function approve(Project $project, ContentItem $item, ContentBranch $branch, ReviewTargetType $type): void
    {
        $versionLabel = app(ContentVersionResolver::class)->versionLabel($item, $branch, $type);

        ReviewRequest::query()->firstOrCreate(
            [
                'project_id' => $project->id,
                'content_branch_id' => $branch->id,
                'review_target_type' => $type->value,
                'version_label' => $versionLabel,
            ],
            [
                'content_item_id' => $item->id,
                'review_target_label' => $type->label(),
                'preview_summary' => 'P5 验收预置：已审核通过版本',
                'preview' => [],
                'status' => ReviewStatus::Approved,
                'submitted_at' => now(),
                'decided_at' => now(),
            ],
        );
    }
}
