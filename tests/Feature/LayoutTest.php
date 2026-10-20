<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Layout: sidebar s filterima projekata, gornja traka i globalna pretraga. */
class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_shows_project_filters_with_counts_and_top_bar(): void
    {
        $user = User::factory()->create(['role' => UserRole::ProjectManager, 'name' => 'Ensar Mešić']);
        foreach ([ProjectStatus::InProgress, ProjectStatus::InProgress, ProjectStatus::OnHold, ProjectStatus::Completed, ProjectStatus::Archived] as $status) {
            Project::factory()->create(['status' => $status, 'responsible_user_id' => $user->id, 'created_by' => $user->id]);
        }
        Project::factory()->create(['status' => ProjectStatus::InProgress]); // tuđi projekat se ne broji
        $user->notify(new WorkspaceNotification('status_changed', 'Test', 'http://localhost'));

        $response = $this->actingAs($user)->get(route('help'))->assertOk();

        $response->assertSeeInOrder(['Projekti', 'Moje stavke', 'Postavke', 'Svi projekti', '4', 'U toku', '2', 'Na čekanju', '1', 'Završeni', '1', 'Arhivirani', '1', 'Pomoć'])
            ->assertSee('Pretraži projekte…')
            ->assertSee('Ctrl + K')
            ->assertSee('Obavijesti (1 nepročitanih)')
            ->assertSee('Ensar Mešić');
    }

    public function test_global_search_finds_only_records_from_member_projects(): void
    {
        $user = User::factory()->create(['role' => UserRole::TeamMember]);
        $mine = Project::factory()->create(['name' => 'FTTH Banovići']);
        $mine->members()->attach($user, ['role' => 'member']);
        $foreign = Project::factory()->create(['name' => 'FTTH Tuzla']);
        $type = CaseType::create(['name' => 'Saglasnost']);
        $status = CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation']);
        foreach ([$mine, $foreign] as $project) {
            AdministrativeCase::create([
                'project_id' => $project->id, 'name' => 'Saglasnost '.$project->name, 'case_type_id' => $type->id, 'case_status_id' => $status->id,
                'responsible_user_id' => $user->id, 'started_at' => now(), 'created_by' => $user->id,
            ])->items()->create(['name' => 'FTTH stavka '.$project->name, 'created_by' => $user->id]);
        }
        $this->actingAs($user);

        Livewire::test('global-search')
            ->set('query', 'F')
            ->assertSee('Upišite najmanje 2 znaka')
            ->set('query', 'FTTH')
            ->assertSee('FTTH Banovići')
            ->assertSee('Saglasnost FTTH Banovići')
            ->assertSee('FTTH stavka FTTH Banovići')
            ->assertDontSee('FTTH Tuzla');
    }
}
