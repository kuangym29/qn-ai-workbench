<?php

namespace Tests\Feature\V2;

use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReviewPageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_formal_page_is_scoped_and_only_needs_project_id(): void
    {
        $this->withoutVite();
        $project = Project::factory()->create();
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $project->id]);
        $this->get("/projects/{$project->id}/reviews")->assertOk()->assertInertia(fn (Assert $page) => $page->component('V2/Review/ReviewCenter')->where('projectId', $project->id));
        $other = Project::factory()->create();
        $this->get("/projects/{$other->id}/reviews")->assertNotFound();
    }

    public function test_page_requires_authentication(): void
    {
        $project = Project::factory()->create();
        $this->get("/projects/{$project->id}/reviews")->assertRedirect('/auth/login');
    }

    public function test_null_preview_is_preserved_in_detail_envelope(): void
    {
        $review = ReviewRequest::factory()->create(['preview' => null]);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $review->project_id]);
        $body = $this->getJson("/api/projects/{$review->project_id}/reviews/{$review->id}")->assertOk()->json();
        $this->assertSame(['data'], array_keys($body));
        $this->assertNull($body['data']['preview']);
    }

    public function test_all_review_api_endpoints_require_authentication(): void
    {
        $review = ReviewRequest::factory()->create();
        $base = "/api/projects/{$review->project_id}/reviews";
        $this->getJson($base)->assertUnauthorized();
        $this->getJson($base.'/'.$review->id)->assertUnauthorized();
        $this->postJson($base.'/'.$review->id.'/decision', ['decision' => 'approved'])->assertUnauthorized();
    }
}
