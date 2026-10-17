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
 * P5-I03 浏览器验收专用演示数据（不挂进 DatabaseSeeder，不在生产逻辑中使用）。
 *
 * 构建单个「青柠育见 · P5-I03 验收」项目，包含：
 *   - 登录用户 i05@example.test / password
 *   - 复用资产索引：人物[妈妈 existing、爸爸 missing、姐姐 existing]、
 *     场景[客厅 existing、公园 missing]
 *   - 一个 image_post 分支（wechat_official + image_post）+ 3 个内容页（P1/P2/P3）
 *
 * 这样浏览器流程可以在同一个项目上下文里：
 *   1. 进入图片批次生产页，给 P1 显式选择 妈妈 + 爸爸 / 客厅；
 *   2. 修改成 姐姐 / 公园；
 *   3. 提交 ImageBatch 审核 → 审核中心通过 → 再改资产 → 验证旧审批失效。
 */
class P5I03BrowserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'i05@example.test'],
            ['name' => 'I05 Browser', 'password' => bcrypt('password')],
        );

        $project = Project::query()->firstOrCreate(
            ['slug' => 'i05-demo'],
            ['name' => '青柠育见 · I05 验收', 'description' => 'I05 浏览器验收演示项目'],
        );

        // ── 复用资产索引（同项目；missing 资产同样可被页面显式引用） ─────────
        $assets = [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Character, '姐姐', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '公园', ReusableAssetStatus::Missing],
        ];
        foreach ($assets as [$type, $name, $status]) {
            ReusableAsset::query()->firstOrCreate(
                ['project_id' => $project->id, 'type' => $type->value, 'name' => $name],
                ['status' => $status->value, 'description' => null, 'thumbnail_url' => null, 'last_used_at' => null],
            );
        }

        // ── image_post 分支 + 3 内容页（同项目） ──────────────────────────
        $item = ContentItem::factory()->create([
            'project_id' => $project->id,
            'title' => '柠檬熊的家庭日常 · P5-I03 验收',
        ]);
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

        unset($user, $branch);
    }
}
