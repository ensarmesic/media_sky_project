<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\GrammaticalGender;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use App\Services\AdministrativeCaseItemService;
use App\Services\AdministrativeCaseService;
use App\Services\ProjectProgressService;
use App\Services\ProjectWorkflowService;
use App\Support\ActivityPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Log aktivnosti, rečenice u historiji i puna historija projekta. */
class ActivityHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $member;

    private Project $project;

    private AdministrativeCase $case;

    private AdministrativeCaseItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager, 'username' => 'ensar', 'name' => 'Ensar Mešić', 'grammatical_gender' => GrammaticalGender::Masculine]);
        $this->member = User::factory()->create(['role' => UserRole::TeamMember, 'username' => 'amra', 'name' => 'Amra Hodžić', 'grammatical_gender' => GrammaticalGender::Feminine]);
        $this->project = Project::factory()->create(['responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $this->project->members()->attach([$this->manager->id => ['role' => 'manager'], $this->member->id => ['role' => 'member']]);
        $this->case = AdministrativeCase::create([
            'project_id' => $this->project->id, 'name' => 'Urbanistička saglasnost',
            'case_type_id' => CaseType::create(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id,
        ]);
        $this->item = $this->case->items()->create(['name' => 'Saglasnost EPBiH', 'status' => CaseItemStatus::InPreparation, 'created_by' => $this->manager->id]);
    }

    private function sentences(): array
    {
        return $this->project->activities()->with('user')->oldest('id')->get()->map(fn (ProjectActivity $activity) => ActivityPresenter::sentence($activity))->all();
    }

    public function test_item_changes_are_logged_with_gendered_sentences(): void
    {
        $service = app(AdministrativeCaseItemService::class);
        $service->changeStatus($this->item, CaseItemStatus::Submitted, $this->manager);
        $service->update($this->item, ['due_date' => '2026-05-10', 'responsible_user_id' => $this->member->id], $this->member);

        $sentences = $this->sentences();
        $this->assertContains('Ensar M. je promijenio status iz U pripremi u Predato', $sentences);
        $this->assertContains('Ensar M. je postavio datum predaje '.today()->format('d.m.Y.'), $sentences);
        $this->assertContains('Amra H. je postavila rok 10.05.2026.', $sentences);
        $this->assertContains('Amra H. je postavila odgovornu osobu Amra H.', $sentences);
    }

    public function test_without_chosen_form_both_endings_are_shown(): void
    {
        $this->manager->update(['grammatical_gender' => null]);
        app(AdministrativeCaseItemService::class)->changeStatus($this->item, CaseItemStatus::Completed, $this->manager->refresh());

        $this->assertContains('Ensar M. je promijenio/la status iz U pripremi u Završeno', $this->sentences());
    }

    public function test_project_changes_are_logged(): void
    {
        $workflow = app(ProjectWorkflowService::class);
        $workflow->setCurrentState($this->project, $this->member, 'Čeka se saglasnost EPBiH');
        $workflow->setNextStep($this->project, $this->member, 'Predati zahtjev za građevinsku dozvolu');
        app(ProjectProgressService::class)->setManualProgress($this->project, $this->manager, 40, 'Procjena');
        $workflow->changeStatus($this->project, $this->manager, ProjectStatus::InProgress);

        $sentences = $this->sentences();
        $this->assertContains('Amra H. je promijenila trenutno stanje: „Čeka se saglasnost EPBiH“', $sentences);
        $this->assertContains('Amra H. je promijenila sljedeći korak: „Predati zahtjev za građevinsku dozvolu“', $sentences);
        $this->assertContains('Ensar M. je promijenio status projekta iz Nije pokrenuto u U toku', $sentences);
        $this->assertTrue(collect($sentences)->contains(fn (string $s) => str_starts_with($s, 'Ensar M. je postavio ručnu procjenu stanja') || str_starts_with($s, 'Ensar M. je promijenio ručnu procjenu stanja')));
    }

    public function test_case_update_logs_only_real_changes(): void
    {
        $service = app(AdministrativeCaseService::class);
        $before = $this->project->activities()->count();

        $service->update($this->case, ['name' => 'Urbanistička saglasnost'], $this->manager);
        $this->assertSame($before, $this->project->activities()->count());

        $service->update($this->case, ['name' => 'Lokacijska saglasnost'], $this->manager);
        $this->assertContains('Ensar M. je promijenio naziv iz Urbanistička saglasnost u Lokacijska saglasnost', $this->sentences());
        $this->assertSame($before + 1, $this->project->activities()->count());
    }

    public function test_events_use_procedure_and_item_terminology(): void
    {
        app(AdministrativeCaseItemService::class)->create($this->case, ['name' => 'Zahtjev za priključak'], $this->member);

        $this->assertContains('Amra H. je kreirala stavku „Zahtjev za priključak“', $this->sentences());
    }

    public function test_history_page_filters_by_type_and_user(): void
    {
        $service = app(AdministrativeCaseItemService::class);
        $service->changeStatus($this->item, CaseItemStatus::Submitted, $this->manager);
        $service->update($this->item, ['due_date' => '2026-05-10'], $this->member);

        $this->actingAs($this->manager);
        Livewire::test('pages::projects.history', ['project' => $this->project])
            ->assertSee('Ensar M. je promijenio status iz U pripremi u Predato')
            ->assertSee('Amra H. je postavila rok 10.05.2026.')
            ->set('type', 'deadline')
            ->assertSee('Amra H. je postavila rok')->assertDontSee('je promijenio status')
            ->set('type', '')->set('userId', (string) $this->manager->id)
            ->assertSee('je promijenio status')->assertDontSee('Amra H. je postavila rok');
    }

    public function test_history_is_hidden_from_non_members(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::TeamMember]))
            ->get(route('projects.history', $this->project))
            ->assertForbidden();
    }

    public function test_user_can_choose_form_of_address_in_profile(): void
    {
        $this->actingAs($this->member);

        Livewire::test('pages::settings.profile')
            ->set('grammatical_gender', 'x')->call('updateProfileInformation')->assertHasErrors(['grammatical_gender'])
            ->set('grammatical_gender', '')->call('updateProfileInformation')->assertHasNoErrors();

        $this->assertNull($this->member->refresh()->grammatical_gender);
        $this->assertSame('promijenio/la', $this->member->verb('promijenio'));
    }
}
