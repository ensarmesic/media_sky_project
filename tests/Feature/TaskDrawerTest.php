<?php

namespace Tests\Feature;

use App\Enums\CaseItemPriority;
use App\Enums\CaseItemStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Detalj zadatka: bočni panel i stranica /zadaci/{id}. */
class TaskDrawerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private AdministrativeCaseItem $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager]);
        $project = Project::factory()->create(['name' => 'FTTH Lukavac', 'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $case = AdministrativeCase::create([
            'project_id' => $project->id, 'name' => 'Urbanistička saglasnost',
            'case_type_id' => CaseType::create(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id,
        ]);
        $this->task = $case->items()->create(['name' => 'Saglasnost Vodovoda', 'created_by' => $this->manager->id]);
    }

    public function test_drawer_opens_on_event_and_shows_breadcrumb(): void
    {
        $this->actingAs($this->manager);

        Livewire::test('task-drawer')
            ->dispatch('open-task', id: $this->task->id)
            ->assertSet('openId', $this->task->id)
            ->assertSee('FTTH Lukavac')
            ->assertSee('Urbanistička saglasnost')
            ->assertSet('form.name', 'Saglasnost Vodovoda')
            ->call('close')
            ->assertSet('openId', null);
    }

    public function test_fields_are_saved_immediately_and_logged(): void
    {
        $assignee = User::factory()->create();
        $this->actingAs($this->manager);

        Livewire::test('task-drawer')
            ->call('open', $this->task->id)
            ->set('form.status', CaseItemStatus::InReview->value)
            ->set('form.priority', CaseItemPriority::Urgent->value)
            ->set('form.responsible_user_id', (string) $assignee->id)
            ->set('form.due_date', today()->addWeek()->toDateString())
            ->set('form.name', 'Saglasnost ViK')
            ->set('form.description', 'Vidi https://example.com')
            ->assertHasNoErrors()
            ->assertDispatched('task-updated');

        $task = $this->task->fresh();
        $this->assertSame(CaseItemStatus::InReview, $task->status);
        $this->assertSame(CaseItemPriority::Urgent, $task->priority);
        $this->assertSame($assignee->id, $task->responsible_user_id);
        $this->assertSame('Saglasnost ViK', $task->name);
        $this->assertSame(today()->addWeek()->toDateString(), $task->due_date->toDateString());
        $this->assertSame(
            ['status', 'priority', 'responsible_user_id', 'due_date', 'name', 'description'],
            $task->activityLog()->where('event_type', 'like', 'task.%')->where('event_type', '!=', 'task.created')->orderBy('id')->get()->pluck('metadata.field')->all(),
        );
    }

    public function test_title_cannot_be_empty(): void
    {
        $this->actingAs($this->manager);

        Livewire::test('task-drawer')
            ->call('open', $this->task->id)
            ->set('form.name', '')
            ->assertHasErrors('form.name');

        $this->assertSame('Saglasnost Vodovoda', $this->task->fresh()->name);
    }

    public function test_checklist_can_be_added_checked_reordered_and_deleted(): void
    {
        $this->actingAs($this->manager);
        $component = Livewire::test('task-drawer')->call('open', $this->task->id);

        foreach (['Prvi', 'Drugi', 'Treći'] as $title) {
            $component->set('newChecklistItem', $title)->call('addChecklistItem');
        }
        [$first, $second, $third] = $this->task->checklistItems()->get()->all();

        $component->call('toggleChecklistItem', $first->id)->assertSee('1/3');
        $component->call('sortChecklist', $third->id, 0);
        $this->assertSame(['Treći', 'Prvi', 'Drugi'], $this->task->checklistItems()->pluck('title')->all());

        $component->call('deleteChecklistItem', $second->id)->assertSee('1/2');
        $this->assertDatabaseMissing('checklist_items', ['id' => $second->id]);
    }

    public function test_complete_duplicate_and_delete(): void
    {
        $this->actingAs($this->manager);
        $this->task->checklistItems()->create(['title' => 'Stavka', 'position' => 1]);

        $component = Livewire::test('task-drawer')->call('open', $this->task->id)->call('markCompleted');
        $this->assertSame(CaseItemStatus::Completed, $this->task->fresh()->status);
        $this->assertNotNull($this->task->fresh()->completed_at);

        $component->call('duplicate');
        $copy = AdministrativeCaseItem::where('name', 'Saglasnost Vodovoda (kopija)')->firstOrFail();
        $this->assertSame(1, $copy->checklistItems()->count());
        $component->assertSet('openId', $copy->id);

        $component->call('delete')->assertSet('openId', null);
        $this->assertFalse($copy->fresh()->is_active);
    }

    public function test_viewer_can_read_but_not_change_task(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));

        Livewire::test('task-drawer')
            ->call('open', $this->task->id)
            ->assertSee('Saglasnost Vodovoda')
            ->set('form.status', CaseItemStatus::Completed->value)
            ->assertForbidden();

        $this->assertSame(CaseItemStatus::New, $this->task->fresh()->status);
    }

    public function test_task_from_inaccessible_project_is_not_opened(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::TeamMember]));

        Livewire::test('task-drawer')
            ->call('open', $this->task->id)
            ->assertSet('openId', null)
            ->assertDontSee('Saglasnost Vodovoda');
    }

    public function test_task_has_its_own_page(): void
    {
        $this->actingAs($this->manager)
            ->get(route('tasks.show', $this->task))
            ->assertOk()
            ->assertSee('Saglasnost Vodovoda')
            ->assertSee('Checklist');

        $this->actingAs(User::factory()->create(['role' => UserRole::TeamMember]))
            ->get(route('tasks.show', $this->task))
            ->assertForbidden();
    }
}
