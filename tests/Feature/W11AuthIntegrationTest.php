<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * DEV-W11 real Auth integration.
 *
 * The W11 UI was written against a frozen contract while the backend was being
 * built in parallel. Now that DEV-AUTH-lite-backend is on main, this file proves
 * the two actually meet: the login page renders, the adapter's endpoints answer the
 * shapes `resources/js/api/auth.ts` declares, and the session behaves the way the
 * UI assumes.
 *
 * No mocks. Real route -> controller -> session -> middleware -> database, and the
 * CSRF check is genuinely enabled rather than short-circuited for tests.
 */
class W11AuthIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('127.0.0.1');
    }

    private function user(): User
    {
        return User::factory()->create(['password' => 'secret-password']);
    }

    /**
     * The adapter types in auth.ts. Transcribed here so a backend rename fails in
     * CI rather than silently producing `undefined` in the browser.
     */
    private const TS_USER_FIELDS = ['id', 'name', 'email'];

    public function test_login_page_renders_for_a_guest_with_a_safe_redirect(): void
    {
        $this->get('/auth/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login', false)
                ->where('redirectTo', '/projects'));

        // An internal destination survives, which is what makes the post-login
        // bounce work.
        $this->get('/auth/login?redirectTo='.urlencode('/projects/1/columns'))
            ->assertInertia(fn (Assert $page) => $page->where('redirectTo', '/projects/1/columns'));
    }

    public function test_authenticated_user_is_redirected_away_from_the_login_page(): void
    {
        $user = $this->user();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertOk();

        $this->get('/auth/login')->assertRedirect('/projects');
    }

    public function test_login_me_and_logout_match_the_adapter_contract(): void
    {
        $user = $this->user();

        // POST /api/auth/login -> 200 with exactly id / name / email
        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'secret-password',
        ])->assertOk();
        $this->assertSame(self::TS_USER_FIELDS, array_keys($login->json('data')));

        // GET /api/auth/me -> same shape
        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertSame(self::TS_USER_FIELDS, array_keys($me->json('data')));
        $this->assertSame($user->id, $me->json('data.id'));
        $this->assertSame($user->email, $me->json('data.email'));
        $this->assertArrayNotHasKey('password', $me->json('data'));
        $this->assertArrayNotHasKey('remember_token', $me->json('data'));

        // POST /api/auth/logout -> 204, and the adapter tolerates an empty body
        $this->postJson('/api/auth/logout')->assertNoContent();

        // Afterwards /me must be 401, which is what drives the UI back to login.
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_wrong_credentials_surface_as_a_422_on_the_email_field(): void
    {
        $user = $this->user();

        // The login form binds errors.email, so the key must exist.
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_guest_is_bounced_from_the_app_and_from_the_api(): void
    {
        // Web page -> login redirect
        $this->get('/projects')->assertRedirect('/auth/login');

        // API -> 401 JSON, not a 302 HTML redirect
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->get('/api/projects')->assertUnauthorized()
            ->assertHeader('content-type', 'application/json');
    }

    public function test_project_context_lifecycle_matches_what_the_selector_expects(): void
    {
        $user = $this->user();
        $project = Project::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertOk();

        // A fresh login starts with no project selected: the selector must show the
        // placeholder, not a stale project from before.
        $this->getJson('/api/projects/current')->assertOk()->assertJsonPath('data', null);

        // Selecting works for a signed-in user.
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $this->getJson('/api/projects/current')->assertOk()->assertJsonPath('data.id', $project->id);

        // Logging out must not leave the project behind in the invalidated session.
        $this->postJson('/api/auth/logout')->assertNoContent();

        // And signing back in must not resurrect it either.
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertOk();
        $this->getJson('/api/projects/current')->assertOk()->assertJsonPath('data', null);
    }

    public function test_real_csrf_protection_applies_to_login_and_logout(): void
    {
        // Turn off the "runningUnitTests" shortcut so the middleware actually runs.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $user = $this->user();
        $this->withSession(['_token' => 'known-csrf-token']);

        // No token -> 419, which the login page renders as "会话已过期…".
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertStatus(419);

        // Correct token -> the same-origin axios flow succeeds.
        $this->postJson('/api/auth/login', [
            '_token' => 'known-csrf-token', 'email' => $user->email, 'password' => 'secret-password',
        ])->assertOk();

        // Logout is protected too, and the token is regenerated afterwards.
        $this->postJson('/api/auth/logout')->assertStatus(419);
        $this->postJson('/api/auth/logout', ['_token' => $this->app['session.store']->token()])
            ->assertNoContent();
    }

    public function test_session_id_rotates_so_a_fixated_session_cannot_survive_login(): void
    {
        $user = $this->user();
        $this->withSession(['visited' => true]);

        $before = $this->app['session.store']->getId();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertOk();

        $this->assertNotSame(
            $before,
            $this->app['session.store']->getId(),
            'the session id must change on login (session fixation defence)'
        );
    }

    public function test_business_apis_work_normally_once_signed_in(): void
    {
        $user = $this->user();
        $project = Project::factory()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertOk();

        // The topbar switcher lists projects only for a signed-in user.
        $this->getJson('/api/projects')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $this->getJson("/api/projects/{$project->id}/columns")->assertOk();
        $this->get('/projects')->assertOk();
    }
}
