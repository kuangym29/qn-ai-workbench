<?php

namespace Database\Seeders;

use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoContent;
use Illuminate\Database\Seeder;

/**
 * V2-P5-I01-R1 浏览器验收种子数据。
 *
 * 与初版的区别：不再预置任何 approved ReviewRequest。
 * R1 要求真实走完用户流程（保存 → 提交审核 → 审核中心通过 → 标记已发布），
 * 因此这里只准备：登录用户 / Project / ContentItem / Branch / 可编辑的正式基础内容。
 */
class P5PublicationBrowserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'p5@example.test'],
            ['name' => 'P5 Browser Acceptance', 'password' => bcrypt('password')],
        );

        $project = Project::query()->firstOrCreate(
            ['slug' => 'p5-pub-demo'],
            ['name' => 'p5-pub-demo', 'description' => 'P5-I01-R1 发布闭环真实浏览器验收演示项目'],
        );

        // ── image_post（wechat_official）—— 图片文章制作分支 ──────────────
        // 注意：不用 ->for($item) / 不依赖 factory 闭包创建关联——本项目多个 factory 的
        // definition 会在 expand 时静默创建孤儿 ContentItem/ContentBranch，
        // 污染浏览器验收的 scope 解析。一律显式传纯 int 外键。
        $ipItem = ContentItem::factory()->create([
            'project_id' => $project->id,
            'title' => 'P5-R1 图片文章',
        ]);
        $ipBranch = ContentBranch::factory()->create([
            'content_item_id' => $ipItem->id,
            'project_id' => $project->id,
            'channel' => 'wechat_official',
            'format' => 'image_post',
        ]);
        ContentPage::factory()->create([
            'content_item_id' => $ipItem->id,
            'project_id' => $project->id,
            'page_no' => 1,
            'final_copy' => '秋天早晚温差加大，带孩子外出时备一件薄外套，出汗后及时更换，避免着凉。',
            'narrative_role' => '开头切入',
            'visual_evidence' => '母亲在小区门口给孩子披上外套',
        ]);
        ContentPage::factory()->create([
            'content_item_id' => $ipItem->id,
            'project_id' => $project->id,
            'page_no' => 2,
            'final_copy' => '室内保持适度湿度，多吃润燥蔬果，作息规律，增强呼吸道抵抗力。',
            'narrative_role' => '建议收尾',
            'visual_evidence' => '孩子在桌前吃梨，窗户半开通风',
        ]);

        // ── article（wechat_official）—— 图文文章分支 ─────────────────────
        $arItem = ContentItem::factory()->create([
            'project_id' => $project->id,
            'title' => 'P5-R1 图文文章',
        ]);
        $arBranch = ContentBranch::factory()->create([
            'content_item_id' => $arItem->id,
            'project_id' => $project->id,
            'channel' => 'wechat_official',
            'format' => 'article',
        ]);
        ArticleContent::factory()->create([
            'project_id' => $project->id,
            'content_item_id' => $arItem->id,
            'content_branch_id' => $arBranch->id,
            'headline' => '秋季幼儿护理三件事',
            'summary' => '换季阶段的穿衣、湿度与作息要点。',
            'body' => '秋天气温变化大，注意给孩子适时添减衣物，保持室内湿度，预防呼吸道不适。',
            'image_suggestions' => [['position' => 1, 'description' => '家长为孩子添衣的场景', 'note' => null]],
            'cover_suggestion' => '温馨家庭场景封面',
        ]);

        // ── video（wechat_channels）—— 视频制作分支 ───────────────────────
        $viItem = ContentItem::factory()->create([
            'project_id' => $project->id,
            'title' => 'P5-R1 视频',
        ]);
        $viBranch = ContentBranch::factory()->create([
            'content_item_id' => $viItem->id,
            'project_id' => $project->id,
            'channel' => 'wechat_channels',
            'format' => 'video',
        ]);
        // VideoContentFactory::definition() 方法体总会创建孤儿 item+branch，绕过 factory 直建。
        VideoContent::create([
            'project_id' => $project->id,
            'content_item_id' => $viItem->id,
            'content_branch_id' => $viBranch->id,
            'batch_instruction' => '以温馨家庭风格呈现秋季幼儿护理要点。',
            'narration' => '换季时节，孩子的日常护理需要注意穿衣、湿度与作息三个方面。',
            'units' => [
                ['source_page_id' => 1, 'dynamic_instruction' => '缓慢推进', 'duration_seconds' => 4, 'note' => '开头'],
                ['source_page_id' => 2, 'dynamic_instruction' => '平移', 'duration_seconds' => 5, 'note' => '中段'],
            ],
        ]);

        // 让登录用户持有该项目（ProjectContext 由点击「进入」设置；这里仅确保项目可被发现）。
        unset($user, $project, $ipItem, $ipBranch, $arItem, $arBranch, $viItem, $viBranch);
    }
}
