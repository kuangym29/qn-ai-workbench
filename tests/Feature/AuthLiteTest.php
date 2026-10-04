<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthLiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('127.0.0.1');
    }

    public function test_guest_boundaries_and_safe_login_redirect(): void
    {
        $this->get('/projects')->assertRedirect('/auth/login');
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->get('/api/projects')->assertUnauthorized()->assertHeader('content-type', 'application/json');
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/logout')->assertUnauthorized();

        $this->get('/auth/login?redirectTo=https://evil.example')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login', false)->where('redirectTo', '/projects'));
        $this->get('/auth/login?redirectTo=%2F%2Fevil.example')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login', false)->where('redirectTo', '/projects'));
        $this->get('/auth/login?redirectTo=%2Fprojects%2F1')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login', false)->where('redirectTo', '/projects/1'));
    }

    public function test_every_business_route_has_auth_middleware(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if ($uri === 'bootstrap' || $uri === 'projects' || str_starts_with($uri, 'projects/') || str_starts_with($uri, 'api/projects')) {
                $this->assertContains('auth', $route->gatherMiddleware(), "Missing auth on {$uri}");
            }
        }
    }

    public function test_login_me_project_context_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $project = Project::factory()->create();
        $this->withSession(['current_project_id' => $project->id]);
        $oldSession = $this->app['session.store']->getId();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertOk()->assertExactJson(['data' => $user->only(['id', 'name', 'email'])]);
        $this->assertNotSame($oldSession, $this->app['session.store']->getId());
        $this->assertNull(session('current_project_id'));
        $this->getJson('/api/auth/me')->assertOk()->assertExactJson(['data' => $user->only(['id', 'name', 'email'])]);
        $this->get('/projects')->assertOk();
        $this->get('/auth/login')->assertRedirect('/projects');
        $this->getJson('/api/projects/current')->assertOk()->assertJsonPath('data', null);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $this->assertSame($project->id, session('current_project_id'));

        $beforeLogoutSession = $this->app['session.store']->getId();
        $beforeLogoutToken = $this->app['session.store']->token();
        $this->postJson('/api/auth/logout')->assertNoContent();
        $this->assertNotSame($beforeLogoutSession, $this->app['session.store']->getId());
        $this->assertNotSame($beforeLogoutToken, $this->app['session.store']->token());
        $this->assertNull(session('current_project_id'));
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->get('/projects')->assertRedirect('/auth/login');

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])->assertOk();
        $this->assertNull(session('current_project_id'));
    }

    public function test_invalid_credentials_are_not_enumerable(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $wrongEmail = $this->postJson('/api/auth/login', ['email' => 'missing@example.test', 'password' => 'secret-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->json('errors.email.0');
        $wrongPassword = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->json('errors.email.0');
        $this->assertSame($wrongEmail, $wrongPassword);
        $this->assertGuest();
    }

    public function test_login_is_throttled(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_login_and_logout_require_real_csrf_tokens(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $user = User::factory()->create(['password' => 'secret-password']);
        $this->withSession(['_token' => 'known-csrf-token']);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertStatus(419);
        $this->postJson('/api/auth/login', [
            '_token' => 'known-csrf-token', 'email' => $user->email, 'password' => 'secret-password',
        ])->assertOk();
        $this->postJson('/api/auth/logout')->assertStatus(419);
        $this->postJson('/api/auth/logout', ['_token' => $this->app['session.store']->token()])
            ->assertNoContent();
    }
}
