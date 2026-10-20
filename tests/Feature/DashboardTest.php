<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_protected_pages_to_login(): void
    {
        foreach (['projects.index', 'my-tasks.index', 'notifications.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_dashboard_redirects_to_projects(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertRedirect('/projekti');
    }

    public function test_main_pages_are_available(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        $this->get(route('projects.index'))->assertOk()->assertSee('Moji zadaci');
        foreach (['projects.create', 'my-tasks.index', 'notifications.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }
}
