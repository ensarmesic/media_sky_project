<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\AdministrativeCaseItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Tok rada: projekat → predmeti → zadaci predmeta. */
class ProjectWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_page_lists_its_cases_with_their_tasks_only(): void
    {
        [$manager, $project, $case] = $this->projectWithCase();
        app(AdministrativeCaseItemService::class)->create($case, ['name' => 'Idejni projekat'], $manager);
        [, , $otherCase] = $this->projectWithCase('Tuđi predmet');
        app(AdministrativeCaseItemService::class)->create($otherCase, ['name' => 'Tuđi zadatak'], $manager);

        $this->actingAs($manager)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Urbanistička saglasnost')
            ->assertSee('Idejni projekat')
            ->assertDontSee('Tuđi predmet')
            ->assertDontSee('Tuđi zadatak');
    }

    public function test_manager_can_create_case_and_add_tasks_inside_project(): void
    {
        [$manager, $project, , $type, $status] = $this->projectWithCase();
        $assignee = User::factory()->create(['role' => UserRole::TeamMember]);
        Notification::fake();
        $this->actingAs($manager);

        $component = Livewire::test('pages::projects.show', ['project' => $project])
            ->call('openCaseModal')
            ->set('caseForm.name', 'Građevinska dozvola')
            ->set('caseForm.case_type_id', (string) $type->id)
            ->set('caseForm.case_status_id', (string) $status->id)
            ->call('saveCase')
            ->assertHasNoErrors();

        $case = $project->administrativeCases()->where('name', 'Građevinska dozvola')->firstOrFail();
        $component->set("newTask.{$case->id}.name", 'Predaja zahtjeva')
            ->set("newTask.{$case->id}.responsible_user_id", (string) $assignee->id)
            ->set("newTask.{$case->id}.due_date", today()->addWeek()->toDateString())
            ->call('addTask', $case->id)
            ->assertHasNoErrors()
            ->assertSee('Predaja zahtjeva');

        $item = $case->items()->firstOrFail();
        $this->assertSame($assignee->id, $item->responsible_user_id);
        Notification::assertSentTo($assignee, WorkspaceNotification::class);
    }

    public function test_tasks_can_be_completed_and_filtered(): void
    {
        [$manager, $project, $case] = $this->projectWithCase();
        $service = app(AdministrativeCaseItemService::class);
        $done = $service->create($case, ['name' => 'Završeni zadatak'], $manager);
        $service->create($case, ['name' => 'Otvoreni zadatak', 'responsible_user_id' => $manager->id], $manager);
        $this->actingAs($manager);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->call('toggleComplete', $done->id)
            ->assertDontSee('Završeni zadatak')
            ->assertSee('Otvoreni zadatak')
            ->set('filter', 'all')
            ->assertSee('Završeni zadatak');

        $this->assertSame(CaseItemStatus::Completed, $done->fresh()->status);
    }

    public function test_assigned_team_member_can_update_own_task_but_not_others(): void
    {
        [$manager, $project, $case] = $this->projectWithCase();
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $service = app(AdministrativeCaseItemService::class);
        $mine = $service->create($case, ['name' => 'Moj zadatak', 'responsible_user_id' => $member->id], $manager);
        $other = $service->create($case, ['name' => 'Tuđi zadatak'], $manager);
        $this->actingAs($member);

        $component = Livewire::test('pages::projects.show', ['project' => $project])
            ->call('changeItemStatus', $mine->id, CaseItemStatus::Submitted->value)
            ->assertHasNoErrors();
        $this->assertSame(CaseItemStatus::Submitted, $mine->fresh()->status);

        $component->call('changeItemStatus', $other->id, CaseItemStatus::Completed->value)->assertForbidden();
        $this->assertSame(CaseItemStatus::NotStarted, $other->fresh()->status);
    }

    public function test_task_link_opens_project_with_task_panel(): void
    {
        [$manager, $project, $case] = $this->projectWithCase();
        $item = app(AdministrativeCaseItemService::class)->create($case, ['name' => 'Saglasnost Vodovoda'], $manager);

        $this->actingAs($manager)
            ->get($item->url())
            ->assertOk()
            ->assertSee('Detalji zadatka', false);
    }

    public function test_my_tasks_lists_only_tasks_assigned_to_me(): void
    {
        [$manager, $project, $case] = $this->projectWithCase();
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $service = app(AdministrativeCaseItemService::class);
        $service->create($case, ['name' => 'Moj zadatak', 'responsible_user_id' => $member->id], $manager);
        $service->create($case, ['name' => 'Zadatak kolege', 'responsible_user_id' => $manager->id], $manager);

        $this->actingAs($member)
            ->get(route('my-tasks.index'))
            ->assertOk()
            ->assertSee('Moj zadatak')
            ->assertSee($project->name)
            ->assertDontSee('Zadatak kolege');
    }

    /** @return array{0: User, 1: Project, 2: AdministrativeCase, 3: CaseType, 4: CaseStatus} */
    private function projectWithCase(string $caseName = 'Urbanistička saglasnost'): array
    {
        $manager = User::factory()->create(['role' => UserRole::ProjectManager]);
        $project = Project::factory()->create(['responsible_user_id' => $manager->id, 'created_by' => $manager->id]);
        $type = CaseType::firstOrCreate(['name' => 'Saglasnost']);
        $status = CaseStatus::firstOrCreate(['system_key' => 'in_preparation'], ['name' => 'U pripremi', 'position' => 1]);
        $case = AdministrativeCase::create([
            'project_id' => $project->id, 'name' => $caseName, 'case_type_id' => $type->id, 'case_status_id' => $status->id,
            'responsible_user_id' => $manager->id, 'started_at' => now(), 'created_by' => $manager->id,
        ]);

        return [$manager, $project, $case, $type, $status];
    }
}
