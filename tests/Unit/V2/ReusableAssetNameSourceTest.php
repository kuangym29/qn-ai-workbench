<?php

namespace Tests\Unit\V2;

use App\Contracts\V2\AssetNameSource;
use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Enums\ReusableAssetType;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\Project;
use App\Services\V2\EmptyAssetReferenceReader;
use App\Services\V2\ReusableAssetIndexService;
use App\Services\V2\ReusableAssetNameSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ImageBatch（B05）AssetNameSource 的兼容实现：
 *   页面 → 显式引用读取器 → 复用索引 → {type, name, status}
 *
 * 这里只验证「兼容形状」，不触碰 ImageBatch 任何代码：
 * 真正绑定由后续 Integration 完成（把本实现绑到 AssetNameSource 上即可）。
 */
class ReusableAssetNameSourceTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private ContentPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
        $item = ContentItem::factory()->for($this->project)->create();
        $this->page = ContentPage::factory()->for($item)->create(['page_no' => 1]);
    }

    public function test_it_implements_the_image_batch_contract(): void
    {
        // I06B：容器默认把 ReusableAssetReferenceReader 绑到 EmptyAssetReferenceReader，
        // 这里用与容器一致的默认依赖构造，验证兼容形状。
        $source = new ReusableAssetNameSource(new EmptyAssetReferenceReader, new ReusableAssetIndexService);
        $this->assertInstanceOf(AssetNameSource::class, $source);
    }

    public function test_default_reader_returns_no_references(): void
    {
        // 默认读取器（EmptyAssetReferenceReader）返回空引用 → references() 为空数组，
        // 行为与 ImageBatch 既有 EmptyAssetNameSource 完全一致。
        $this->assertSame([], (new ReusableAssetNameSource(new EmptyAssetReferenceReader, new ReusableAssetIndexService))->references($this->page));
    }

    public function test_index_status_is_mapped_for_referenced_names(): void
    {
        $index = new ReusableAssetIndexService;
        $index->markExisting($this->project, ReusableAssetType::Character, '柠檬熊');
        $index->markMissing($this->project, ReusableAssetType::Scene, '海边落日');

        $source = new ReusableAssetNameSource($this->reader([
            ['type' => 'character', 'name' => '柠檬熊'],
            ['type' => 'scene', 'name' => '海边落日'],
            ['type' => 'character', 'name' => '未登记人物'],
        ]), $index);

        $this->assertSame([
            ['type' => 'character', 'name' => '柠檬熊', 'status' => 'existing'],
            ['type' => 'scene', 'name' => '海边落日', 'status' => 'missing'],
            ['type' => 'character', 'name' => '未登记人物', 'status' => 'missing'],
        ], $source->references($this->page));
    }

    public function test_references_are_scoped_to_the_page_project(): void
    {
        $other = Project::factory()->create();
        $index = new ReusableAssetIndexService;
        $index->markExisting($other, ReusableAssetType::Character, '柠檬熊');

        $source = new ReusableAssetNameSource($this->reader([
            ['type' => 'character', 'name' => '柠檬熊'],
        ]), $index);

        $this->assertSame([
            ['type' => 'character', 'name' => '柠檬熊', 'status' => 'missing'],
        ], $source->references($this->page));
    }

    public function test_invalid_or_empty_references_are_skipped(): void
    {
        $source = new ReusableAssetNameSource($this->reader([
            ['type' => 'prop', 'name' => '临时道具'],
            ['type' => 'scene', 'name' => '   '],
        ]), new ReusableAssetIndexService);

        $this->assertSame([], $source->references($this->page));
    }

    /**
     * @param  list<array{type: string, name: string}>  $names
     */
    private function reader(array $names): ReusableAssetReferenceReader
    {
        return new class($names) implements ReusableAssetReferenceReader
        {
            /** @param  list<array{type: string, name: string}>  $names */
            public function __construct(private readonly array $names) {}

            public function names(ContentPage $page): array
            {
                return $this->names;
            }
        };
    }
}
