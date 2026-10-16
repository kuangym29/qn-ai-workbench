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
 * V2-P5-I02 浏览器验收种子数据（复用资产管理入口）。
 *
 * 只做三件事：
 *   1. 一个可登录用户（p5i02@example.test / password）；
 *   2. 目标项目 `p5i02-assets`：人物 2 条（1 existing + 1 missing）、场景 2 条（1 existing + 1 missing），
 *      刻意不含任何 `data:` 缩略图 —— 新增 / 编辑可写的 thumbnail_url 只允许 http/https/站内 `/`，
 *      data: 会被正式校验拒绝，种子里放 data: 会掩盖真实契约；
 *   3. 第二个项目 `p5i02-other`：用于跨 Project 404 验收（它自己不带任何资产）。
 *
 * 依赖 factory 会静默创建孤儿记录，这里一律显式写字段。
 * 幂等：updateOrCreate，可重复运行（验收脚本每次 migrate:fresh 后调用一次）。
 */
class ReusableAssetBrowserSeeder extends Seeder
{
    public const EMAIL = 'p5i02@example.test';

    public const PASSWORD = 'password';

    public const PROJECT_NAME = 'P5-I02 复用资产验收';

    public const PROJECT_SLUG = 'p5i02-assets';

    public const OTHER_PROJECT_NAME = 'P5-I02 跨项目隔离';

    public const OTHER_PROJECT_SLUG = 'p5i02-other';

    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'P5-I02 Browser Acceptance', 'password' => bcrypt(self::PASSWORD)],
        );

        $project = Project::query()->updateOrCreate(
            ['slug' => self::PROJECT_SLUG],
            ['name' => self::PROJECT_NAME, 'description' => 'P5-I02 复用资产管理入口真实浏览器验收项目'],
        );

        Project::query()->updateOrCreate(
            ['slug' => self::OTHER_PROJECT_SLUG],
            ['name' => self::OTHER_PROJECT_NAME, 'description' => '用于验证跨 Project 404 隔离'],
        );

        $asset = static function (
            Project $project,
            ReusableAssetType $type,
            string $name,
            ReusableAssetStatus $status,
            ?string $description,
            ?string $lastUsedAt,
        ): void {
            ReusableAsset::query()->updateOrCreate(
                ['project_id' => $project->id, 'type' => $type->value, 'name' => $name],
                [
                    'status' => $status->value,
                    'description' => $description,
                    'thumbnail_url' => null,
                    'last_used_at' => $lastUsedAt === null ? null : Carbon::parse($lastUsedAt),
                ],
            );
        };

        // 人物：1 existing（有说明、有使用时间）+ 1 missing（无说明、未使用）。
        $asset($project, ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing, '30 岁左右，齐肩短发，浅色家居服；稳定主视觉。', '2026-10-01 09:30:00');
        $asset($project, ReusableAssetType::Character, '弟弟', ReusableAssetStatus::Missing, null, null);

        // 场景：1 existing + 1 missing。
        $asset($project, ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing, '暖色调现代客厅，布艺沙发 + 落地窗；固定机位。', '2026-09-28 18:05:00');
        $asset($project, ReusableAssetType::Scene, '厨房', ReusableAssetStatus::Missing, null, null);
    }
}
