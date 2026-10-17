<?php

namespace Tests\Feature\V2;

use App\Contracts\V2\AssetNameSource;
use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Models\Project;
use App\Services\V2\ContentPageReusableAssetReferenceReader;
use App\Services\V2\EmptyAssetReferenceReader;
use App\Services\V2\ReusableAssetNameSource;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Tests\TestCase;

class PromotionStage2BIntegrationTest extends TestCase
{
    public function test_all_formal_pages_and_api_families_remain_reachable(): void
    {
        $branch = '/projects/1/content/1/branches/1/';
        $api = '/api/projects/1/items/1/branches/1/';
        $cases = [
            ['GET', $branch.'image-post', 'V2ContentController@imagePostPage'],
            ['GET', $branch.'article', 'V2\\ArticleImportController@editor'],
            ['GET', $branch.'video', 'V2\\VideoController@page'],
            ['GET', $branch.'image-batch', 'V2ContentController@imageBatchPage'],
            ['GET', '/projects/1/reusable-assets', 'V2\\ReusableAssetController@page'],
            ['GET', '/projects/1/reviews', 'V2\\ReviewController@page'],
            ['GET', $api.'image-post', 'V2\\ImagePostImportController@show'],
            ['PUT', $api.'image-post', 'V2\\ImagePostImportController@save'],
            ['POST', $api.'image-post/preview', 'V2\\ImagePostImportController@preview'],
            ['POST', $api.'image-post/apply', 'V2\\ImagePostImportController@apply'],
            ['GET', $api.'article', 'V2\\ArticleImportController@show'],
            ['PUT', $api.'article', 'V2\\ArticleImportController@save'],
            ['POST', $api.'article/preview', 'V2\\ArticleImportController@preview'],
            ['POST', $api.'article/apply', 'V2\\ArticleImportController@apply'],
            ['GET', $api.'video', 'V2\\VideoController@show'],
            ['PUT', $api.'video', 'V2\\VideoController@save'],
            ['POST', $api.'video/source-change', 'V2\\VideoController@sourceChange'],
            ['GET', $api.'image-batch', 'V2\\ImageBatchController@show'],
            ['PUT', $api.'image-batch', 'V2\\ImageBatchController@save'],
            ['POST', $api.'image-batch/run', 'V2\\ImageBatchController@run'],
            ['POST', $api.'image-batch/copy-task', 'V2\\ImageBatchController@copyTask'],
            ['POST', $api.'image-batch/units/1/source-change', 'V2\\ImageBatchController@sourceChange'],
            ['GET', '/api/projects/1/reusable-assets', 'V2\\ReusableAssetController@index'],
            ['GET', '/api/projects/1/reviews', 'V2\\ReviewController@index'],
            ['GET', '/api/projects/1/reviews/1', 'V2\\ReviewController@show'],
            ['POST', '/api/projects/1/reviews/1/decision', 'V2\\ReviewController@decide'],
        ];
        foreach ($cases as [$method, $url, $action]) {
            $route = app('router')->getRoutes()->match(Request::create($url, $method));
            $this->assertSame('App\\Http\\Controllers\\'.$action, $route->getActionName(), $method.' '.$url);
            $this->assertContains('auth', $route->gatherMiddleware(), $method.' '.$url);
        }
    }

    public function test_route_declarations_have_no_duplicate_method_domain_uri(): void
    {
        $collection = new class extends RouteCollection {
            public array $declarations = [];

            public function add(Route $route)
            {
                foreach ($route->methods() as $method) {
                    $key = $method.'|'.$route->getDomain().'|'.$route->uri();
                    $this->declarations[$key] = ($this->declarations[$key] ?? 0) + 1;
                }
                return parent::add($route);
            }
        };
        app('router')->setRoutes($collection);
        require base_path('routes/web.php');
        $this->assertSame([], array_filter($collection->declarations, fn ($count) => $count > 1));
    }

    public function test_retired_review_placeholder_is_not_a_second_formal_entry(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app('router')->getRoutes()->match(Request::create('/projects/1/review', 'GET'));
    }

    public function test_project_preserves_foundation_assets_and_review_relations(): void
    {
        $project = new Project;
        foreach (['inspirations', 'contentBranches', 'contentColumns', 'topics', 'contentItems', 'sourceReferences', 'productionTasks', 'channelTasks', 'files', 'assets', 'reusableAssets', 'reviewRequests'] as $name) {
            $this->assertTrue(method_exists($project, $name), $name);
            $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $project->$name());
        }
    }

    public function test_production_asset_bindings_keep_the_explicit_empty_reference_boundary(): void
    {
        $this->assertInstanceOf(ReusableAssetNameSource::class, app(AssetNameSource::class));
        // P5-I03：「页面显式引用了哪些人物 / 场景」首次有正式真源，生产容器绑定
        // ContentPageReusableAssetReferenceReader，正式替换 EmptyAssetReferenceReader
        // （后者保留为测试 / fallback 类，生产容器不再绑定它）。
        $this->assertInstanceOf(ContentPageReusableAssetReferenceReader::class, app(ReusableAssetReferenceReader::class));
        $this->assertNotInstanceOf(EmptyAssetReferenceReader::class, app(ReusableAssetReferenceReader::class));
    }
}
