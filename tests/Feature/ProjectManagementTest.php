<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use App\Services\ProjectActivityService;
use App\Services\ProjectProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_create_update_archive_and_restore_project(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $category = ProjectCategory::factory()->create();

        $project = Project::create([
            'code' => 'PRJ-001', 'name' => 'Testni projekat', 'category_id' => $category->id,
            'start_date' => '2026-10-19', 'responsible_user_id' => $admin->id,
            'status' => ProjectStatus::Planned, 'created_by' => $admin->id,
        ]);
        $project->update(['name' => 'Izmijenjen projekat']);
        $project->delete();

        $this->assertSoftDeleted($project);
        $project->restore();
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Izmijenjen projekat', 'deleted_at' => null]);
    }

    public function test_project_requires_unique_code_and_valid_dates(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $category = ProjectCategory::factory()->create();
        Project::factory()->create(['code' => 'PRJ-001']);

        $this->actingAs($admin);

        Livewire::test('pages::projects.form')
            ->set('form.code', 'PRJ-001')
            ->set('form.name', 'Novi projekat')
            ->set('form.category_id', (string) $category->id)
            ->set('form.start_date', '2026-10-20')
            ->set('form.planned_end_date', '2026-10-19')
            ->set('form.responsible_user_id', (string) $admin->id)
            ->call('save')
            ->assertHasErrors(['form.code' => 'unique', 'form.planned_end_date' => 'after_or_equal']);
    }

    public function test_administrator_can_use_project_and_category_pages(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create(['responsible_user_id' => $admin->id, 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('projects.show', $project))->assertOk()->assertSee($project->name);
        $this->get(route('projects.edit', $project))->assertOk();
        $this->get(route('categories.index'))->assertOk();
    }

    public function test_viewer_cannot_change_project(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $project = Project::factory()->create();
        $this->assertFalse($viewer->can('update', $project));
        $this->assertFalse($viewer->can('archive', $project));
    }

    public function test_manual_progress_is_audited(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create(['progress' => 10]);

        app(ProjectProgressService::class)->setManualProgress($project, $admin, 35, 'Završena prva faza');

        $this->assertDatabaseHas('project_progress_changes', ['project_id' => $project->id, 'user_id' => $admin->id, 'previous_progress' => 10, 'new_progress' => 35, 'reason' => 'Završena prva faza']);
        $this->assertSame(35, $project->fresh()->progress);
    }

    public function test_project_activity_tab_contains_only_its_own_activity(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create();
        $otherProject = Project::factory()->create();
        app(ProjectActivityService::class)->record($project, 'note', 'Aktivnost ovog projekta', $admin);
        app(ProjectActivityService::class)->record($otherProject, 'note', 'Aktivnost drugog projekta', $admin);

        $this->actingAs($admin)
            ->get(route('projects.show', ['project' => $project, 'prikaz' => 'istorija']))
            ->assertOk()
            ->assertSee('Aktivnost ovog projekta')
            ->assertDontSee('Aktivnost drugog projekta');
    }
}
