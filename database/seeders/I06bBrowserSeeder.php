<?php

namespace Database\Seeders;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\PageType;
use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\Project;
use App\Models\ReusableAsset;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * I06B 浏览器验收专用演示数据（不挂进 DatabaseSeeder，不在生产逻辑中使用）。
 *
 * 构建单个「青柠育见 · I06B 验收」项目，包含：
 *   - 登录用户 i06b@example.test / password
 *   - 复用资产索引：人物[妈妈(existing)、爸爸(missing)]、场景[客厅(existing)、厨房(missing)]
 *   - 一个 image_post 分支（wechat_official + image_post）+ 3 个内容页（P1/P2/P3）
 *
 * 这样浏览器流程可以在同一个项目上下文里先后进入：
 *   复用资产索引页（/projects/{project}/reusable-assets）
 *   图片批次生产页（/projects/{project}/content/{item}/branches/{branch}/image-batch）
 *
 * 注意：I06B 默认 ReusableAssetReferenceReader 绑定为 EmptyAssetReferenceReader，
 * 因此 ImageBatch 的 assets 应为空（暂无资产检查数据）—— 这是 I06B 生产语义，不是缺陷。
 */
class I06bBrowserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'i06b@example.test'],
            ['name' => 'I06B Browser', 'password' => bcrypt('password')],
        );

        $project = Project::query()->firstOrCreate(
            ['slug' => 'i06b-demo'],
            ['name' => '青柠育见 · I06B 验收', 'description' => 'I06B 浏览器验收演示项目'],
        );

        // ── 复用资产索引（同项目） ─────────────────────────────────────────
        $assets = [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing, '柠檬黄连衣裙的小女孩，固定主角', true],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing, null, false],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing, '暖色调客厅，木地板与布艺沙发', true],
            [ReusableAssetType::Scene, '厨房', ReusableAssetStatus::Missing, null, false],
        ];
        foreach ($assets as [$type, $name, $status, $description, $withThumb]) {
            ReusableAsset::query()->firstOrCreate(
                ['project_id' => $project->id, 'type' => $type->value, 'name' => $name],
                [
                    'status' => $status->value,
                    'description' => $description,
                    'thumbnail_url' => $withThumb ? 'data:image/svg+xml;charset=UTF-8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" rx="12" fill="%23FBAC1D"/></svg>') : null,
                    'last_used_at' => null,
                ],
            );
        }

        // ── image_post 分支 + 3 内容页（同项目） ──────────────────────────
        $item = ContentItem::factory()->create(['project_id' => $project->id]);
        $branch = ContentBranch::factory()->for($item)->create([
            'project_id' => $project->id,
            'channel' => Channel::WechatOfficial->value,
            'format' => ContentFormat::ImagePost->value,
        ]);

        $pages = [
            1 => ['final_copy' => '柠檬熊在客厅给爸爸做早餐', 'visual_evidence' => '清晨厨房暖光'],
            2 => ['final_copy' => '妈妈带柠檬熊去公园放风筝', 'visual_evidence' => '草地蓝天'],
            3 => ['final_copy' => '一家人围坐在客厅吃晚饭', 'visual_evidence' => '餐桌暖灯'],
        ];
        foreach ($pages as $no => $data) {
            ContentPage::factory()->for($item)->create([
                'project_id' => $project->id,
                'page_no' => $no,
                'page_type' => PageType::Content->value,
                'final_copy' => $data['final_copy'],
                'visual_evidence' => $data['visual_evidence'],
            ]);
        }

        // 让登录用户持有该项目（ProjectContext 依赖 session 中的 current_project_id，
        // 由点击「进入」设置；这里仅确保项目可被发现）。
        unset($user, $branch);
    }
}
