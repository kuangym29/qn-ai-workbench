<?php

namespace Tests\Concerns;

use App\Models\User;

trait AuthenticatesUser
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }
}
