<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_inertia_bootstrap_page(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Bootstrap'));
    }

    public function test_project_and_column_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('projects', ['id', 'name', 'slug']));
        $this->assertTrue(Schema::hasColumns('content_columns', ['id', 'project_id', 'name', 'slug']));
    }
}
