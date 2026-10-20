<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Institution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Detalj stavke: bočni panel i stranica /stavke/{id}. */
class TaskDrawerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private AdministrativeCaseItem $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager]);
        $project = Project::factory()->create(['name' => 'FTTH Banovići', 'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $project->members()->attach($this->manager, ['role' => 'manager']);
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
            ->assertSee('FTTH Banovići')
            ->assertSee('Urbanistička saglasnost')
            ->assertSet('form.name', 'Saglasnost Vodovoda')
            ->call('close')
            ->assertSet('openId', null);
    }

    public function test_fields_are_saved_immediately_and_logged(): void
    {
        $assignee = User::factory()->create();
        $this->task->administrativeCase->project->members()->attach($assignee, ['role' => 'member']);
        $institution = Institution::create(['name' => 'JKP Vodovod']);
        $this->actingAs($this->manager);

        Livewire::test('task-drawer')
            ->call('open', $this->task->id)
            ->set('form.institution_id', (string) $institution->id)
            ->set('form.responsible_user_id', (string) $assignee->id)
            ->set('form.due_date', today()->addWeek()->toDateString())
            ->set('form.submitted_at', today()->subDay()->toDateString())
            ->set('form.name', 'Saglasnost ViK')
            ->set('form.description', 'Vidi https://example.com')
            ->assertHasNoErrors()
            ->assertDispatched('task-updated');

        $task = $this->task->fresh();
        $this->assertSame($institution->id, $task->institution_id);
        $this->assertSame($assignee->id, $task->responsible_user_id);
        $this->assertSame('Saglasnost ViK', $task->name);
        $this->assertSame(today()->subDay()->toDateString(), $task->submitted_at->toDateString());
        $this->assertSame(
            ['institution_id', 'responsible_user_id', 'due_date', 'submitted_at', 'name', 'description'],
            $task->activityLog()->where('event_type', '!=', 'task.created')->orderBy('id')->get()->pluck('metadata.field')->all(),
        );
    }

    public function test_workflow_buttons_submit_supplement_and_complete(): void
    {
        $this->actingAs($this->manager);
        $component = Livewire::test('task-drawer')->call('open', $this->task->id);

        $component->call('markSubmitted')->assertSet('form.status', 'submitted');
        $this->assertTrue($this->task->fresh()->submitted_at->isToday());

        $component->call('markSupplementRequired');
        $this->assertSame(CaseItemStatus::SupplementRequired, $this->task->fresh()->status);

        $component->call('markCompleted');
        $this->assertSame(CaseItemStatus::Completed, $this->task->fresh()->status);
        $this->assertTrue($this->task->fresh()->response_at->isToday());
    }

    public function test_record_response_with_protocol_number_and_letter_in_one_step(): void
    {
        Storage::fake('local');
        $this->task->update(['status' => CaseItemStatus::WaitingResponse, 'submitted_at' => today()->subDays(10)]);
        $this->actingAs($this->manager);

        Livewire::test('task-drawer')
            ->call('open', $this->task->id)
            ->call('openResponseForm')
            ->set('response.date', today()->subDay()->toDateString())
            ->set('response.protocol_number', '02-15-321/26')
            ->set('response.outcome', CaseItemStatus::SupplementRequired->value)
            ->set('responseFile', UploadedFile::fake()->create('dopis.pdf', 120))
            ->call('recordResponse')
            ->assertHasNoErrors()
            ->assertSet('showResponseForm', false);

        $task = $this->task->fresh();
        $this->assertSame(CaseItemStatus::SupplementRequired, $task->status);
        $this->assertSame(today()->subDay()->toDateString(), $task->response_at->toDateString());
        $document = $task->documents()->firstOrFail();
        $this->assertSame('incoming', $document->direction);
        $this->assertSame('02-15-321/26', $document->protocol_number);
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

    public function test_duplicate_and_delete(): void
    {
        $this->actingAs($this->manager);
        $this->task->checklistItems()->create(['title' => 'Podzadatak', 'position' => 1]);

        $component = Livewire::test('task-drawer')->call('open', $this->task->id)->call('duplicate');
        $copy = AdministrativeCaseItem::where('name', 'Saglasnost Vodovoda (kopija)')->firstOrFail();
        $this->assertSame(1, $copy->checklistItems()->count());
        $component->assertSet('openId', $copy->id);

        $component->call('delete')->assertSet('openId', null);
        $this->assertFalse($copy->fresh()->is_active);
    }

    public function test_viewer_member_can_read_but_not_change_item(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $this->task->administrativeCase->project->members()->attach($viewer, ['role' => 'member']);
        $this->actingAs($viewer);

        Livewire::test('task-drawer')
            ->call('open', $this->task->id)
            ->assertSee('Saglasnost Vodovoda')
            ->assertDontSee('Označi kao predato')
            ->set('form.status', CaseItemStatus::Completed->value)
            ->assertForbidden();

        $this->assertSame(CaseItemStatus::NotStarted, $this->task->fresh()->status);
    }

    public function test_item_from_inaccessible_project_is_not_opened(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::TeamMember]));

        Livewire::test('task-drawer')
            ->call('open', $this->task->id)
            ->assertSet('openId', null)
            ->assertDontSee('Saglasnost Vodovoda');
    }

    public function test_item_has_its_own_page_and_old_links_redirect(): void
    {
        $this->actingAs($this->manager)
            ->get(route('tasks.show', $this->task))
            ->assertOk()
            ->assertSee('Saglasnost Vodovoda')
            ->assertSee('Checklist')
            ->assertSee('Dokumenti');
        $this->get('/zadaci/'.$this->task->id)->assertRedirect('/stavke/'.$this->task->id);

        $this->actingAs(User::factory()->create(['role' => UserRole::TeamMember]))
            ->get(route('tasks.show', $this->task))
            ->assertForbidden();
    }
}
