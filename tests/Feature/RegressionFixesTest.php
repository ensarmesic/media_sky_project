<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\AdministrativeCaseItemService;
use App\Services\AdministrativeCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class RegressionFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_complete_project_through_livewire_action(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $project = Project::factory()->create();
        $project->members()->attach($viewer, ['role' => 'member']);
        $this->actingAs($viewer);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->call('completeProject')
            ->assertForbidden();

        $this->assertSame(ProjectStatus::Planned, $project->fresh()->status);
    }

    public function test_cases_can_be_reordered_even_when_positions_were_equal(): void
    {
        [$admin, $project, $type, $status] = $this->setUpProject();
        $first = AdministrativeCase::create($this->caseData($project, $admin, $type, $status, 'Prvi'));
        $second = AdministrativeCase::create($this->caseData($project, $admin, $type, $status, 'Drugi'));

        app(AdministrativeCaseService::class)->movePosition($second, 'up');

        $this->assertSame(['Drugi', 'Prvi'], $project->administrativeCases()->pluck('name')->all());
        $this->assertNotSame($first->fresh()->position, $second->fresh()->position);
    }

    public function test_first_workspace_notification_is_delivered_and_mail_skipped_without_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => null]);

        $user->notify(new WorkspaceNotification('status_changed', 'Test', 'http://localhost'));

        Notification::assertSentTo($user, WorkspaceNotification::class, fn ($sent, array $channels) => $channels === ['database']);
    }

    public function test_waiting_reminder_is_not_sent_immediately_after_status_change(): void
    {
        [$admin, $project, $type, $status] = $this->setUpProject();
        $case = $this->createCase($project, $admin, $type, $status);
        $service = app(AdministrativeCaseItemService::class);
        $item = $service->create($case, ['name' => 'Zahtjev', 'responsible_user_id' => $admin->id], $admin);

        $service->changeStatus($item, CaseItemStatus::WaitingResponse, $admin);
        Notification::fake();
        $this->artisan('items:send-waiting-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->travel(15)->days();
        $this->artisan('items:send-waiting-reminders')->assertSuccessful();
        Notification::assertSentTo($admin, WorkspaceNotification::class);
    }

    /** @return array{0: User, 1: Project, 2: CaseType, 3: CaseStatus} */
    private function setUpProject(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create(['responsible_user_id' => $admin->id, 'created_by' => $admin->id]);
        $type = CaseType::create(['name' => 'Saglasnost']);
        $status = CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation', 'position' => 1]);

        return [$admin, $project, $type, $status];
    }

    private function createCase(Project $project, User $user, CaseType $type, CaseStatus $status): AdministrativeCase
    {
        return AdministrativeCase::create($this->caseData($project, $user, $type, $status, 'Urbanistička saglasnost'));
    }

    /** @return array<string, mixed> */
    private function caseData(Project $project, User $user, CaseType $type, CaseStatus $status, string $name): array
    {
        return ['project_id' => $project->id, 'name' => $name, 'case_type_id' => $type->id, 'responsible_user_id' => $user->id, 'started_at' => now(), 'case_status_id' => $status->id, 'created_by' => $user->id];
    }
}
