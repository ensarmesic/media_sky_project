<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Institution;
use App\Models\Project;
use App\Models\User;
use App\Services\AdministrativeCaseItemService;
use App\Services\CaseTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Detalj projekta: zaglavlje, postupci (harmonika + tabela stavki) i desna kolona. */
class ProjectDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Project $project;

    private AdministrativeCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        CaseType::create(['name' => 'Saglasnost']);
        CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation', 'position' => 1]);
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager, 'name' => 'Ensar Mešić']);
        $this->project = Project::factory()->create([
            'name' => 'FTTH Banovići', 'description' => 'Podzemna optička mreža – GPON', 'location' => 'Banovići',
            'status' => ProjectStatus::InProgress, 'progress' => 65, 'current_state' => 'Čeka se odgovor EPBiH.',
            'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id,
        ]);
        $this->project->members()->attach($this->manager, ['role' => 'manager']);
        $this->case = AdministrativeCase::create([
            'project_id' => $this->project->id, 'name' => 'Urbanistička saglasnost', 'icon' => 'document-text',
            'institution_id' => Institution::create(['name' => 'Općina Banovići'])->id,
            'case_type_id' => CaseType::first()->id, 'case_status_id' => CaseStatus::first()->id,
            'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id,
        ]);
    }

    public function test_page_shows_header_cases_table_and_side_cards(): void
    {
        $this->item('Idejni projekat', CaseItemStatus::Completed, ['recipient' => 'Interno', 'submitted_at' => '2026-04-08', 'due_date' => '2026-04-10']);
        $this->item('Saglasnost EPBiH', CaseItemStatus::WaitingResponse, ['recipient' => 'JP EPBiH', 'responsible_user_id' => $this->manager->id, 'submitted_at' => today()->subDays(12)]);

        $this->actingAs($this->manager)->get(route('projects.show', $this->project))
            ->assertOk()
            ->assertSee('FTTH Banovići')->assertSee('Podzemna optička mreža – GPON')->assertSee('Banovići')
            ->assertSee('Administrativni napredak')->assertSee('50%')->assertSee('Ručna procjena stanja')->assertSee('65%')
            ->assertSee('Postupci u projektu')->assertSee('Svi (1)')->assertSee('Čeka odgovor (1)')
            ->assertSee('Urbanistička saglasnost')->assertSee('Općina Banovići')->assertSee('1 / 2')
            ->assertSee('08.04.2026.')->assertSee('Ensar M.')
            ->assertSee('Trenutno stanje')->assertSee('Čeka se odgovor EPBiH.')
            ->assertSee('Zahtijeva pažnju')->assertSee('Čeka se odgovor (12 dana)')
            ->assertSee('Posljednje aktivnosti')->assertSee('Dokumenti projekta')->assertSee('Članovi projekta')->assertSee('Voditelj projekta');
    }

    public function test_case_filter_tabs_use_computed_case_status(): void
    {
        $this->item('Stavka 1', CaseItemStatus::Completed);
        $other = AdministrativeCase::create([...$this->case->only(['project_id', 'case_type_id', 'case_status_id', 'responsible_user_id', 'started_at', 'created_by']), 'name' => 'Građevinska dozvola']);
        $other->items()->create(['name' => 'Glavni projekat', 'created_by' => $this->manager->id]);
        $this->actingAs($this->manager);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->assertSee('Završeno (1)')->assertSee('Nije pokrenuto (1)')
            ->set('caseFilter', 'completed')->assertSee('Urbanistička saglasnost')->assertDontSee('Građevinska dozvola')
            ->set('caseFilter', 'not_started')->assertSee('Građevinska dozvola')->assertDontSee('Urbanistička saglasnost');
    }

    public function test_manager_adds_case_from_template_and_quick_item(): void
    {
        $template = app(CaseTemplateService::class)->save($this->manager, ['name' => 'Saglasnost', 'case_type_id' => CaseType::first()->id], [['name' => 'Zahtjev'], ['name' => 'Rješenje']]);
        $this->actingAs($this->manager);

        $component = Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('openCaseModal')
            ->set('caseForm.name', 'Saglasnost Elektroprivrede')
            ->set('caseForm.template_id', (string) $template->id)
            ->call('saveCase')
            ->assertHasNoErrors()
            ->assertSee('Saglasnost Elektroprivrede');

        $case = $this->project->administrativeCases()->where('name', 'Saglasnost Elektroprivrede')->firstOrFail();
        $this->assertSame(['Zahtjev', 'Rješenje'], $case->items()->pluck('name')->all());

        $component->set("quickItem.{$case->id}", 'Uplata naknade')->call('addItem', $case->id)->assertHasNoErrors();
        $this->assertSame(CaseItemStatus::NotStarted, $case->items()->where('name', 'Uplata naknade')->firstOrFail()->status);
    }

    public function test_status_chip_changes_status_and_sets_submission_date(): void
    {
        $item = $this->item('Saglasnost BH Telecoma', CaseItemStatus::InPreparation);
        $this->actingAs($this->manager);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('changeItemStatus', $item->id, CaseItemStatus::Submitted->value)
            ->assertHasNoErrors();

        $this->assertSame(CaseItemStatus::Submitted, $item->fresh()->status);
        $this->assertTrue($item->fresh()->submitted_at->isToday());
        $this->assertDatabaseHas('project_activities', ['subject_id' => $item->id, 'event_type' => 'task.status']);
    }

    public function test_items_and_cases_can_be_reordered_and_items_duplicated_or_deleted(): void
    {
        $first = $this->item('Prva', CaseItemStatus::NotStarted);
        $second = $this->item('Druga', CaseItemStatus::NotStarted);
        $this->actingAs($this->manager);

        $component = Livewire::test('pages::projects.show', ['project' => $this->project])->call('sortItems', $second->id, 0);
        $this->assertSame(['Druga', 'Prva'], $this->case->items()->pluck('name')->all());

        $component->call('duplicateItem', $first->id)->call('deleteItem', $second->id);
        $this->assertSame(['Prva', 'Prva (kopija)'], $this->case->items()->where('is_active', true)->pluck('name')->all());
    }

    public function test_side_cards_progress_and_members_are_editable(): void
    {
        $colleague = User::factory()->create(['name' => 'Mirza Smajić']);
        $this->actingAs($this->manager);

        Livewire::test('pages::projects.show', ['project' => $this->project])
            ->set('currentState', 'Predat zahtjev Općini.')
            ->set('nextStep', 'Pripremiti dopunu za Vodovod.')
            ->call('openProgressModal')->set('manualProgress', 80)->call('saveProgress')
            ->set('showMembersModal', true)->set('newMemberId', (string) $colleague->id)->call('addMember')
            ->assertHasNoErrors();

        $project = $this->project->fresh();
        $this->assertSame('Predat zahtjev Općini.', $project->current_state);
        $this->assertSame('Pripremiti dopunu za Vodovod.', $project->next_step);
        $this->assertSame(80, $project->progress);
        $this->assertTrue($project->members()->whereKey($colleague->id)->exists());
        $this->assertDatabaseHas('project_activities', ['project_id' => $project->id, 'event_type' => 'project.current_state']);
    }

    public function test_team_member_can_change_only_own_item_status(): void
    {
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $mine = $this->item('Moja stavka', CaseItemStatus::NotStarted, ['responsible_user_id' => $member->id]);
        $other = $this->item('Tuđa stavka', CaseItemStatus::NotStarted);
        $this->project->members()->syncWithoutDetaching([$member->id => ['role' => 'member']]);
        $this->actingAs($member);

        $component = Livewire::test('pages::projects.show', ['project' => $this->project])
            ->call('changeItemStatus', $mine->id, CaseItemStatus::InPreparation->value)->assertHasNoErrors();
        $this->assertSame(CaseItemStatus::InPreparation, $mine->fresh()->status);

        $component->call('changeItemStatus', $other->id, CaseItemStatus::Completed->value)->assertForbidden();
        $this->assertSame(CaseItemStatus::NotStarted, $other->fresh()->status);
        Livewire::test('pages::projects.show', ['project' => $this->project])->call('openCaseModal')->assertForbidden();
    }

    public function test_viewer_member_cannot_complete_project(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $this->project->members()->attach($viewer, ['role' => 'member']);
        $this->actingAs($viewer);

        Livewire::test('pages::projects.show', ['project' => $this->project])->call('completeProject')->assertForbidden();
        $this->assertSame(ProjectStatus::InProgress, $this->project->fresh()->status);
    }

    public function test_page_uses_constant_number_of_queries(): void
    {
        foreach (range(1, 5) as $caseNumber) {
            $case = AdministrativeCase::create([...$this->case->only(['project_id', 'case_type_id', 'case_status_id', 'responsible_user_id', 'started_at', 'created_by']), 'name' => 'Postupak '.$caseNumber]);
            foreach (range(1, 10) as $i) {
                app(AdministrativeCaseItemService::class)->create($case, ['name' => "Stavka $caseNumber-$i", 'responsible_user_id' => User::factory()->create()->id, 'due_date' => today()->addDays($i - 3)], $this->manager);
            }
        }
        $this->actingAs($this->manager);

        DB::enableQueryLog();
        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('Stavka 5-10');
        $this->assertLessThan(40, count(DB::getQueryLog()), 'Previše upita — moguć N+1.');
    }

    private function item(string $name, CaseItemStatus $status, array $attributes = []): AdministrativeCaseItem
    {
        return $this->case->items()->create([...$attributes, 'name' => $name, 'status' => $status, 'created_by' => $this->manager->id]);
    }
}
