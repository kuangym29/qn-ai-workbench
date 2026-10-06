<?php

namespace App\Services\HistoryImport;

use App\Enums\PageType;
use App\Enums\SourceRole;

class YujianHistoryManifest
{
    public const PROJECT_NAME = '青柠育见';

    public const PROJECT_SLUG = 'qingning-yujian';

    public static function columns(): array
    {
        return [
            ['name' => '生活小能力', 'slug' => 'life-skills', 'sort_order' => 1],
            ['name' => '看见小情绪', 'slug' => 'emotions', 'sort_order' => 2],
            ['name' => '原来在长大', 'slug' => 'growing-up', 'sort_order' => 3],
            ['name' => '安心小日常', 'slug' => 'daily-peace', 'sort_order' => 4],
            ['name' => '相处小智慧', 'slug' => 'siblings-social', 'sort_order' => 5],
            ['name' => '爸妈在成长', 'slug' => 'parent-growth', 'sort_order' => 6],
        ];
    }

    public static function items(): array
    {
        return [
            [
                'title' => '孩子出门总磨蹭',
                'column_slug' => 'life-skills',
                'confirmed_date' => '2026-09-27',
                'final_path' => '01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md',
                'script_path' => '2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/孩子出门总磨蹭_十页补全_待确认/逐页内容脚本_待用户确认.md',
                'page_types' => 'cover,content,content,content,content,content,content,content,column_closing,fixed_back_cover',
            ],
            [
                'title' => '积木倒了，孩子哭了',
                'column_slug' => 'emotions',
                'confirmed_date' => '2026-09-27',
                'final_path' => '01_2.5D家庭IP形象/看见小情绪/图文/最终上图文案.md',
                'script_path' => '2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/试稿_看见小情绪_积木倒了/11_v6九页逐页确认卡_待用户确认.md',
                'page_types' => 'cover,content,content,content,content,content,content,column_closing,fixed_back_cover',
            ],
            [
                'title' => '弟弟想玩车，姐姐还没玩完',
                'column_slug' => 'siblings-social',
                'confirmed_date' => '2026-09-30',
                'final_path' => '01_2.5D家庭IP形象/相处小智慧/图文/最终上图文案.md',
                'script_path' => '2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/试稿_相处小智慧_姐弟都想玩小车/03_十页逐页确认卡_v2_待用户确认.md',
                'page_types' => 'cover,content,content,content,content,content,content,content,column_closing,fixed_back_cover',
            ],
            [
                'title' => '一只纸箱，开了家水果店',
                'column_slug' => 'growing-up',
                'confirmed_date' => '2026-09-29',
                'final_path' => '01_2.5D家庭IP形象/原来在长大/图文/最终上图文案.md',
                'script_path' => '2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/试稿_原来在长大_纸箱水果店/01_单篇生产任务单_逐页内容待确认.md',
                'page_types' => 'cover,content,content,content,content,content,column_closing,fixed_back_cover',
            ],
        ];
    }

    public static function expectedTypes(array $item): array
    {
        return array_map(PageType::from(...), explode(',', $item['page_types']));
    }

    public static function projectSources(): array
    {
        return [
            [SourceRole::ContentLedger, '00_总入口与归档索引/六栏目内容台账.md'],
            [SourceRole::ClosingLineRegistry, '00_总入口与归档索引/2.5D栏目收尾文案台账.md'],
            [SourceRole::NavigationIndex, '00_总入口与归档索引/2.5D逐篇最终上图文案.md'],
        ];
    }

    public static function allPaths(): array
    {
        $paths = array_map(fn (array $entry): string => $entry[1], self::projectSources());
        foreach (self::items() as $item) {
            $paths[] = $item['final_path'];
            $paths[] = $item['script_path'];
        }

        return $paths;
    }

    /** Historical source_path remains the import identity; this is the current file address. */
    public static function currentSourcePath(string $sourcePath): string
    {
        $locations = [
            '01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md' => '2.5D家庭IP形象/01_栏目内容项目/01_生活小能力/图文/最终上图文案.md',
            '2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/孩子出门总磨蹭_十页补全_待确认/逐页内容脚本_待用户确认.md' => '2.5D家庭IP形象/01_栏目内容项目/01_生活小能力/生活小能力_孩子出门总磨蹭_20260929/02_图文/90_既有制作源/孩子出门总磨蹭_十页补全_待确认/逐页内容脚本_待用户确认.md',
            '01_2.5D家庭IP形象/看见小情绪/图文/最终上图文案.md' => '2.5D家庭IP形象/01_栏目内容项目/02_看见小情绪/图文/最终上图文案.md',
            '2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/试稿_看见小情绪_积木倒了/11_v6九页逐页确认卡_待用户确认.md' => '2.5D家庭IP形象/01_栏目内容项目/02_看见小情绪/看见小情绪_积木倒了，孩子哭了_20260929/02_图文/90_既有制作源/试稿_看见小情绪_积木倒了/11_v6九页逐页确认卡_待用户确认.md',
            '01_2.5D家庭IP形象/相处小智慧/图文/最终上图文案.md' => '2.5D家庭IP形象/01_栏目内容项目/05_相处小智慧/图文/最终上图文案.md',
            '2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/试稿_相处小智慧_姐弟都想玩小车/03_十页逐页确认卡_v2_待用户确认.md' => '2.5D家庭IP形象/01_栏目内容项目/05_相处小智慧/相处小智慧_姐弟都想玩小车_20260930/02_图文/90_既有制作源/试稿_相处小智慧_姐弟都想玩小车/03_十页逐页确认卡_v2_待用户确认.md',
            '01_2.5D家庭IP形象/原来在长大/图文/最终上图文案.md' => '2.5D家庭IP形象/01_栏目内容项目/03_原来在长大/图文/最终上图文案.md',
            '2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/试稿_原来在长大_纸箱水果店/01_单篇生产任务单_逐页内容待确认.md' => '2.5D家庭IP形象/01_栏目内容项目/03_原来在长大/原来在长大_纸箱水果店_20260930/02_图文/90_既有制作源/试稿_原来在长大_纸箱水果店/01_单篇生产任务单_逐页内容待确认.md',
        ];

        return $locations[$sourcePath] ?? $sourcePath;
    }
}
