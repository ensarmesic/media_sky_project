<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use App\Services\AdministrativeCaseItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_list_contains_only_visible_projects(): void
    {
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $visibleProject = Project::factory()->create(['name' => 'Vidljivi projekat']);
        $hiddenProject = Project::factory()->create(['name' => 'Skriveni projekat']);
        $visibleProject->members()->attach($member, ['role' => 'member']);
        $type = CaseType::create(['name' => 'Zahtjev']);
        $status = CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation']);
        foreach ([$visibleProject, $hiddenProject] as $project) {
            AdministrativeCase::create([
                'project_id' => $project->id, 'name' => 'Predmet '.$project->name, 'case_type_id' => $type->id, 'case_status_id' => $status->id,
                'responsible_user_id' => $project->responsible_user_id, 'started_at' => now(), 'created_by' => $project->created_by,
            ])->items()->create(['name' => 'Zadatak '.$project->name, 'created_by' => $project->created_by]);
        }

        $this->actingAs($member)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Vidljivi projekat')
            ->assertDontSee('Skriveni projekat');
    }

    public function test_member_cannot_open_project_they_do_not_belong_to(): void
    {
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $hiddenProject = Project::factory()->create();

        $this->actingAs($member)->get(route('projects.show', $hiddenProject))->assertForbidden();
    }

    public function test_user_assigned_to_an_item_becomes_project_member(): void
    {
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $manager = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create();
        $case = AdministrativeCase::create([
            'project_id' => $project->id, 'name' => 'Dodijeljeni postupak', 'case_type_id' => CaseType::create(['name' => 'Zahtjev'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $manager->id, 'started_at' => now(), 'created_by' => $manager->id,
        ]);
        $this->actingAs($member)->get(route('projects.show', $project))->assertForbidden();

        app(AdministrativeCaseItemService::class)->create($case, ['name' => 'Stavka', 'responsible_user_id' => $member->id], $manager);

        $this->assertSame('member', $project->members()->whereKey($member->id)->first()->pivot->role);
        $this->actingAs($member)->get(route('projects.show', $project))->assertOk()->assertSee('Dodijeljeni postupak');
    }

    public function test_viewer_role_sees_only_projects_where_it_is_a_member(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $project = Project::factory()->create();

        $this->actingAs($viewer)->get(route('projects.show', $project))->assertForbidden();
        $project->members()->attach($viewer, ['role' => 'member']);
        $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk();
    }

    public function test_project_manager_cannot_create_case_in_project_they_do_not_manage(): void
    {
        $manager = User::factory()->create(['role' => UserRole::ProjectManager]);
        $project = Project::factory()->create();
        $project->members()->attach($manager, ['role' => 'member']);
        $this->actingAs($manager);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->call('openCaseModal')
            ->assertForbidden();
    }
}
