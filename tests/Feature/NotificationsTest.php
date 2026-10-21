<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\NotificationKind;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\AdministrativeCaseItemService;
use App\Services\CommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Obavijesti: okidači, dnevni podsjetnici i zvono. */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $member;

    private Project $project;

    private AdministrativeCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager, 'username' => 'ensar']);
        $this->member = User::factory()->create(['role' => UserRole::TeamMember, 'username' => 'amra']);
        $this->project = Project::factory()->create(['responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $this->project->members()->attach([$this->manager->id => ['role' => 'manager'], $this->member->id => ['role' => 'member']]);
        $this->case = AdministrativeCase::create([
            'project_id' => $this->project->id, 'name' => 'Urbanistička saglasnost',
            'case_type_id' => CaseType::create(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id,
        ]);
    }

    private function item(array $attributes = []): AdministrativeCaseItem
    {
        return $this->case->items()->create(['name' => 'Saglasnost EPBiH', 'status' => CaseItemStatus::InPreparation, 'responsible_user_id' => $this->member->id, 'created_by' => $this->manager->id, ...$attributes]);
    }

    private function assertKindSent(User $user, NotificationKind $kind, int $times = 1): void
    {
        Notification::assertSentToTimes($user, WorkspaceNotification::class, $times);
        Notification::assertSentTo($user, WorkspaceNotification::class, fn (WorkspaceNotification $n) => $n->kind === $kind->value);
    }

    public function test_assignment_status_change_and_comment_notify_responsible_but_not_own_actions(): void
    {
        Notification::fake();
        $this->actingAs($this->manager);
        $service = app(AdministrativeCaseItemService::class);

        $item = $service->create($this->case, ['name' => 'Zahtjev ViK', 'responsible_user_id' => $this->member->id], $this->manager);
        Notification::assertSentTo($this->member, WorkspaceNotification::class, fn (WorkspaceNotification $n) => $n->kind === NotificationKind::TaskAssigned->value && $n->url === $item->url());

        $service->changeStatus($item, CaseItemStatus::Submitted, $this->manager);
        Notification::assertSentTo($this->member, WorkspaceNotification::class, fn (WorkspaceNotification $n) => $n->kind === NotificationKind::StatusChanged->value);

        app(CommentService::class)->create($item, $this->manager, 'Pogledaj dopis');
        Notification::assertSentTo($this->member, WorkspaceNotification::class, fn (WorkspaceNotification $n) => $n->kind === NotificationKind::TaskCommented->value && str_contains($n->url, '#komentar-'));

        // Vlastita akcija: odgovorna osoba mijenja svoju stavku → nema obavijesti.
        $this->actingAs($this->member);
        $service->changeStatus($item, CaseItemStatus::WaitingResponse, $this->member);
        Notification::assertSentToTimes($this->member, WorkspaceNotification::class, 3);
    }

    public function test_adding_member_through_project_form_notifies_new_member(): void
    {
        Notification::fake();
        $newcomer = User::factory()->create(['role' => UserRole::TeamMember]);
        $this->actingAs($this->manager);

        Livewire::test('pages::projects.form', ['project' => $this->project])
            ->set('form.member_ids', [$this->member->id, $newcomer->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertKindSent($newcomer, NotificationKind::ProjectMember);
        Notification::assertNotSentTo($this->member, WorkspaceNotification::class);
    }

    public function test_deadline_reminder_is_sent_when_due_tomorrow_and_when_overdue_once(): void
    {
        $tomorrow = $this->item(['due_date' => today()->addDay()]);
        $this->item(['name' => 'Za tri dana', 'due_date' => today()->addDays(3)]);
        $overdue = $this->item(['name' => 'Kasni', 'due_date' => today()->subDay()]);
        Notification::fake();

        $this->artisan('items:send-deadline-reminders')->assertSuccessful();
        $this->artisan('items:send-deadline-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->member, WorkspaceNotification::class, 2);
        Notification::assertSentTo($this->member, WorkspaceNotification::class, fn (WorkspaceNotification $n) => $n->title === 'Rok je sutra: '.$tomorrow->name);
        Notification::assertSentTo($this->member, WorkspaceNotification::class, fn (WorkspaceNotification $n) => $n->title === 'Rok je istekao: '.$overdue->name);
    }

    public function test_waiting_longer_than_ten_days_is_reminded(): void
    {
        $this->item(['status' => CaseItemStatus::WaitingResponse, 'submitted_at' => today()->subDays(12)]);
        $this->item(['name' => 'Tek predato', 'status' => CaseItemStatus::WaitingResponse, 'submitted_at' => today()->subDays(4)]);
        Notification::fake();

        $this->artisan('items:send-waiting-reminders')->assertSuccessful();
        $this->artisan('items:send-waiting-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->member, WorkspaceNotification::class, 1);
        Notification::assertSentTo($this->member, WorkspaceNotification::class, fn (WorkspaceNotification $n) => $n->title === 'Stavka čeka odgovor 12 dana: Saglasnost EPBiH');
    }

    public function test_bell_shows_unread_count_opens_notification_and_marks_all_read(): void
    {
        $item = $this->item();
        $this->member->notify(new WorkspaceNotification(NotificationKind::Mentioned->value, 'Spomenuti ste u komentaru', $item->url().'#komentar-1'));
        $this->member->notify(new WorkspaceNotification(NotificationKind::TaskAssigned->value, 'Dodijeljena vam je stavka', $item->url()));
        $first = $this->member->notifications()->where('data->kind', NotificationKind::Mentioned->value)->firstOrFail();
        $this->actingAs($this->member);

        Livewire::test('notification-bell')
            ->assertSee('2 nepročitanih')->assertSee('Spomenuti ste u komentaru')
            ->call('open', $first->id)
            ->assertRedirect($item->url().'#komentar-1');
        $this->assertNotNull($first->fresh()->read_at);

        Livewire::test('notification-bell')
            ->assertSee('1 nepročitanih')
            ->call('markAllAsRead')
            ->assertDontSee('nepročitanih');
        $this->assertSame(0, $this->member->unreadNotifications()->count());
    }

    public function test_notification_with_external_url_redirects_to_notification_list(): void
    {
        $this->member->notify(new WorkspaceNotification(NotificationKind::StatusChanged->value, 'Sumnjiv link', 'https://evil.example/phish'));
        $this->actingAs($this->member);

        Livewire::test('pages::notifications.index')
            ->call('open', $this->member->notifications()->firstOrFail()->id)
            ->assertRedirect(route('notifications.index'));
    }

    public function test_user_can_mute_kinds_and_muted_kind_is_not_sent(): void
    {
        $this->actingAs($this->member);
        $kept = collect(NotificationKind::cases())->reject(fn ($kind) => $kind === NotificationKind::StatusChanged)->map->value->values()->all();

        Livewire::test('pages::settings.notifications')
            ->assertSee('Promijenjen je status moje stavke')
            ->set('kinds', $kept)
            ->set('daily_digest', true)->set('digest_hour', 8)
            ->call('save')->assertHasNoErrors();

        $setting = $this->member->refresh()->notificationSettingFor();
        $this->assertSame([NotificationKind::StatusChanged->value], $setting->muted_kinds);
        $this->assertTrue($setting->daily_digest);
        $this->assertSame(8, $setting->digest_hour);

        Notification::fake();
        $this->actingAs($this->manager);
        $service = app(AdministrativeCaseItemService::class);
        $service->changeStatus($this->item(), CaseItemStatus::Submitted, $this->manager);
        Notification::assertNotSentTo($this->member, WorkspaceNotification::class);
    }

    public function test_user_cannot_open_someone_elses_notification(): void
    {
        $this->manager->notify(new WorkspaceNotification(NotificationKind::StatusChanged->value, 'Tuđa', route('projects.index')));
        $this->actingAs($this->member);

        Livewire::test('notification-bell')
            ->call('open', $this->manager->notifications()->firstOrFail()->id)
            ->assertNotFound();
    }
}
