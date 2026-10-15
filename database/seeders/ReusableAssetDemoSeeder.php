<?php

namespace Database\Seeders;

use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Models\Project;
use App\Models\ReusableAsset;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 开发演示数据（I06A 浏览器验收专用）—— 只在显式调用时运行：
 *   php artisan db:seed --class=ReusableAssetDemoSeeder
 *
 * 不挂进 DatabaseSeeder，不进入任何生产逻辑；人物 / 场景名称仅用于本地演示。
 *
 * 覆盖验收面：
 *   - character existing（含缩略图 / 无缩略图 / null description / null last_used_at）
 *   - character missing
 *   - scene existing（含长名称）
 *   - scene missing
 *   - 一个完全没有资产的空项目（用于空结果验收）
 */
class ReusableAssetDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );

        $project = Project::query()->firstOrCreate(
            ['slug' => 'demo-qingning'],
            ['name' => '青柠育见 · 演示项目', 'description' => 'I06A 复用资产索引本地浏览器验收项目'],
        );

        // 空项目：用于空结果验收。
        Project::query()->firstOrCreate(
            ['slug' => 'empty-project'],
            ['name' => '空项目（无复用资产）', 'description' => '进入后人物 / 场景均为空，用于 EmptyState 验收'],
        );

        $svg = static function (string $label, string $fill): string {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96">'
                .'<rect width="96" height="96" rx="12" fill="'.$fill.'"/>'
                .'<text x="48" y="60" font-size="40" text-anchor="middle" fill="#475569" font-family="sans-serif">'
                .e($label).'</text></svg>';

            return 'data:image/svg+xml;charset=UTF-8,'.rawurlencode($svg);
        };

        $character = static function (
            string $name,
            string $status,
            ?string $description,
            ?string $lastUsed,
            ?string $thumbnail,
        ) use ($project, $svg): void {
            ReusableAsset::query()->updateOrCreate(
                ['project_id' => $project->id, 'type' => ReusableAssetType::Character->value, 'name' => $name],
                [
                    'status' => $status,
                    'description' => $description,
                    'last_used_at' => $lastUsed ? Carbon::parse($lastUsed) : null,
                    'thumbnail_url' => $thumbnail === 'svg' ? $svg(mb_substr($name, 0, 1), '#fde68a') : $thumbnail,
                ],
            );
        };

        $scene = static function (
            string $name,
            string $status,
            ?string $description,
            ?string $lastUsed,
            ?string $thumbnail,
        ) use ($project, $svg): void {
            ReusableAsset::query()->updateOrCreate(
                ['project_id' => $project->id, 'type' => ReusableAssetType::Scene->value, 'name' => $name],
                [
                    'status' => $status,
                    'description' => $description,
                    'last_used_at' => $lastUsed ? Carbon::parse($lastUsed) : null,
                    'thumbnail_url' => $thumbnail === 'svg' ? $svg(mb_substr($name, 0, 1), '#bbf7d0') : $thumbnail,
                ],
            );
        };

        // ── 人物：existing / missing，含 null 边界 ──
        $character('妈妈', ReusableAssetStatus::Existing->value, '30 岁左右，齐肩短发，常穿浅色家居服；温柔耐心的家庭主视觉。', '2026-09-28 10:12:00', 'svg');
        $character('爸爸', ReusableAssetStatus::Existing->value, '30 岁左右，短发，戴细框眼镜；日常衬衫 / T 恤。', '2026-09-25 20:40:00', 'svg');
        $character('姐姐', ReusableAssetStatus::Existing->value, '学龄前女孩，扎两个小辫，常穿明亮色上衣。', '2026-09-29 08:05:00', 'svg');
        // existing 但从未使用、无缩略图（null 边界）。
        $character('爷爷', ReusableAssetStatus::Existing->value, null, null, null);
        // missing：无缩略图、从未使用。
        $character('弟弟', ReusableAssetStatus::Missing->value, '幼儿男孩，学步期；尚无稳定参考，需要先建立统一形象。', null, null);
        $character('奶奶', ReusableAssetStatus::Missing->value, '约 61 岁，和蔼微胖；需要建立固定发型与服装参考。', null, null);

        // ── 场景：existing / missing，含长名称与 null 边界 ──
        $scene('客厅', ReusableAssetStatus::Existing->value, '暖色调现代家庭客厅，布艺沙发 + 落地窗；固定机位基础构图。', '2026-09-29 08:10:00', 'svg');
        $scene('儿童房', ReusableAssetStatus::Existing->value, '浅色木家具，靠墙小书桌与玩具收纳架；柔和自然光。', '2026-09-26 19:20:00', 'svg');
        // 长名称 + 已有但无缩略图（边界）。
        $scene('小区楼下花园', ReusableAssetStatus::Existing->value, '绿植步道与儿童活动区；固定取位为长椅一侧三分之一构图。', '2026-09-10 17:45:00', null);
        // 已有但从未使用。
        $scene('办公室', ReusableAssetStatus::Existing->value, '白色办公桌 + 双显示器；工位固定机位，背景为百叶窗。', null, 'svg');
        // missing：无缩略图。
        $scene('厨房', ReusableAssetStatus::Missing->value, '开放式小厨房，L 型台面；需要建立稳定视角与光线参考。', null, null);
    }
}
