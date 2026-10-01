<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_redirects_to_the_project_workspace(): void
    {
        // Project is the highest-level entry: the landing route forwards to the project list.
        $this->get('/')->assertRedirect('/projects');
    }

    public function test_bootstrap_remains_available_as_a_technical_connectivity_page(): void
    {
        $this->get('/bootstrap')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Bootstrap'));
    }

    public function test_project_and_column_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('projects', ['id', 'name', 'slug']));
        $this->assertTrue(Schema::hasColumns('content_columns', ['id', 'project_id', 'name', 'slug']));
    }
}
