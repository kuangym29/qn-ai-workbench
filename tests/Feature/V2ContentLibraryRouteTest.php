<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class V2ContentLibraryRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_is_authenticated_and_scoped(): void
    {
        $item = ContentItem::factory()->create();
        $foreign = ContentItem::factory()->create();
        $base = '/projects/'.$item->project_id.'/content/';
        $this->get($base.$item->id)->assertRedirect('/auth/login');
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $this->get($base.$item->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ContentLibrary/Show')->where('projectId', $item->project_id)->where('itemId', $item->id));
        $this->get($base.$foreign->id)->assertNotFound();
    }
}
