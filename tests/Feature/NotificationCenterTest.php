<?php

namespace Tests\Feature;

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

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_deadline_command_notifies_responsible_person_once(): void
    {
        $user = User::factory()->create();
        $case = AdministrativeCase::create([
            'project_id' => Project::factory()->create()->id, 'name' => 'Predmet', 'case_type_id' => CaseType::create(['name' => 'Dozvola'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $user->id, 'started_at' => today(), 'created_by' => $user->id,
        ]);
        $item = app(AdministrativeCaseItemService::class)->create($case, ['name' => 'Saglasnost EPBiH', 'responsible_user_id' => $user->id, 'due_date' => today()->addDay()->toDateString()], $user);
        Notification::fake();

        $this->artisan('items:send-deadline-reminders')->assertSuccessful();
        $this->artisan('items:send-deadline-reminders')->assertSuccessful();

        Notification::assertSentToTimes($user, WorkspaceNotification::class, 1);
        $this->assertNotNull($item->fresh()->deadline_reminded_at);
    }

    public function test_user_can_read_and_delete_their_notification(): void
    {
        $user = User::factory()->create();
        $user->notify(new WorkspaceNotification('deadline_soon', 'Približava se rok zadatka: Saglasnost', route('projects.index'), today()->toDateString()));
        $notification = $user->notifications()->firstOrFail();
        $this->actingAs($user);

        Livewire::test('pages::notifications.index')
            ->assertSee('Približava se rok zadatka: Saglasnost')
            ->call('markAsRead', $notification->id)
            ->assertHasNoErrors();

        $this->assertNotNull($notification->fresh()->read_at);

        Livewire::test('pages::notifications.index')
            ->call('delete', $notification->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_notification_page_requires_authentication(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_configurable_case_reminder_is_sent_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $type = CaseType::create(['name' => 'Dozvola']);
        $status = CaseStatus::create(['name' => 'Čeka odgovor', 'system_key' => 'waiting']);
        $case = AdministrativeCase::create([
            'project_id' => Project::factory()->create()->id,
            'name' => 'Predmet s podsjetnikom',
            'case_type_id' => $type->id,
            'responsible_user_id' => $user->id,
            'started_at' => today(),
            'response_due_at' => today()->addDays(8),
            'reminder_days_before' => 10,
            'case_status_id' => $status->id,
            'created_by' => $user->id,
        ]);

        $this->artisan('cases:send-deadline-reminders')->assertSuccessful();
        $this->artisan('cases:send-deadline-reminders')->assertSuccessful();

        Notification::assertSentToTimes($user, WorkspaceNotification::class, 1);
        $this->assertNotNull($case->fresh()->deadline_reminded_at);
    }
}
