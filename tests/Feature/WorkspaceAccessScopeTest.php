<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
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
        Project::factory()->create(['name' => 'Skriveni projekat']);
        $visibleProject->members()->attach($member, ['role' => 'member']);

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

    public function test_user_assigned_to_a_case_can_open_its_project(): void
    {
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $project = Project::factory()->create();
        AdministrativeCase::create([
            'project_id' => $project->id, 'name' => 'Dodijeljeni predmet', 'case_type_id' => CaseType::create(['name' => 'Zahtjev'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $member->id, 'started_at' => now(), 'created_by' => $member->id,
        ]);

        $this->actingAs($member)->get(route('projects.show', $project))->assertOk()->assertSee('Dodijeljeni predmet');
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
