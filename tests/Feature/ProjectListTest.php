<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use App\Services\AttentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Lista projekata /projekti i logika "Zahtijeva pažnju". */
class ProjectListTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager]);
    }

    public function test_project_card_shows_details_progress_and_members(): void
    {
        $project = $this->project('FTTH Banovići', ['description' => 'Podzemna optička mreža – GPON', 'location' => 'Banovići', 'start_date' => '2025-04-24', 'planned_end_date' => '2026-06-30']);
        $project->members()->attach($this->manager, ['role' => 'manager']);
        $case = $this->case($project);
        foreach ([CaseItemStatus::Completed, CaseItemStatus::Completed, CaseItemStatus::Completed, CaseItemStatus::NotStarted, CaseItemStatus::Cancelled] as $status) {
            $this->item($case, 'Stavka', $status);
        }

        $this->actingAs($this->manager)->get(route('projects.index'))
            ->assertOk()
            ->assertSee('FTTH Banovići')
            ->assertSee('Podzemna optička mreža – GPON')
            ->assertSee('Banovići')
            ->assertSee('24.04.2025. – 30.06.2026.')
            ->assertSee('75%')
            ->assertSee('U toku');
    }

    public function test_attention_shows_at_most_three_items_with_reasons(): void
    {
        $project = $this->project('FTTH Banovići');
        $case = $this->case($project);
        $this->item($case, 'Saglasnost EPBiH', CaseItemStatus::WaitingResponse, ['submitted_at' => today()->subDays(12)]);
        $this->item($case, 'Saglasnost Vodovoda', CaseItemStatus::InPreparation, ['due_date' => today()->addDays(3)]);
        $this->item($case, 'Kasni zahtjev', CaseItemStatus::Submitted, ['due_date' => today()->subDays(2)]);
        $this->item($case, 'Rok sutra', CaseItemStatus::NotStarted, ['due_date' => today()->addDay()]);
        $this->item($case, 'Daleki rok', CaseItemStatus::NotStarted, ['due_date' => today()->addDays(30)]);
        $this->item($case, 'Završena', CaseItemStatus::Completed, ['due_date' => today()->subDays(5)]);

        $entries = app(AttentionService::class)->forProject($project->id);
        $this->assertSame(['Kasni zahtjev', 'Saglasnost EPBiH', 'Rok sutra', 'Saglasnost Vodovoda'], $entries->pluck('item.name')->all());
        $this->assertSame(['Predato, kasni 2 dana', 'Čeka se odgovor (12 dana)', 'Nije pokrenuto, rok je sutra', 'U pripremi, rok za 3 dana'], $entries->pluck('reason')->all());

        $this->actingAs($this->manager)->get(route('projects.index'))
            ->assertSeeInOrder(['Kasni zahtjev', 'Saglasnost EPBiH', 'Rok sutra'])
            ->assertDontSee('Saglasnost Vodovoda')
            ->assertDontSee('Daleki rok');
    }

    public function test_status_filter_search_and_sort_via_url(): void
    {
        $this->project('Bravo u toku', ['status' => ProjectStatus::InProgress, 'planned_end_date' => '2027-01-01']);
        $this->project('Alfa na čekanju', ['status' => ProjectStatus::OnHold, 'planned_end_date' => '2026-01-01']);
        $this->project('Stari arhivirani', ['status' => ProjectStatus::Archived]);
        $this->actingAs($this->manager);

        $this->get(route('projects.index'))->assertSee('Bravo u toku')->assertSee('Alfa na čekanju')->assertDontSee('Stari arhivirani');
        $this->get(route('projects.index', ['status' => 'archived']))->assertSee('Stari arhivirani')->assertDontSee('Bravo u toku');
        $this->get(route('projects.index', ['status' => 'on_hold']))->assertSee('Alfa na čekanju')->assertDontSee('Bravo u toku');
        $this->get(route('projects.index', ['q' => 'bravo']))->assertSee('Bravo u toku')->assertDontSee('Alfa na čekanju');

        Livewire::withQueryParams(['sort' => 'name'])->test('pages::projects.index')->assertSeeInOrder(['Alfa na čekanju', 'Bravo u toku']);
        Livewire::withQueryParams(['sort' => 'deadline'])->test('pages::projects.index')->assertSeeInOrder(['Alfa na čekanju', 'Bravo u toku']);
    }

    public function test_user_sees_only_member_projects(): void
    {
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $this->project('Moj projekat')->members()->attach($member, ['role' => 'member']);
        $this->project('Tuđi projekat');

        $this->actingAs($member)->get(route('projects.index'))->assertSee('Moj projekat')->assertDontSee('Tuđi projekat');
    }

    public function test_page_uses_constant_number_of_queries(): void
    {
        foreach (range(1, 15) as $number) {
            $project = $this->project('Projekat '.$number);
            $project->members()->attach(User::factory()->count(3)->create());
            $case = $this->case($project);
            foreach (range(1, 4) as $i) {
                $this->item($case, "Stavka $i", CaseItemStatus::WaitingResponse, ['submitted_at' => today()->subDays(15), 'due_date' => today()->subDay()]);
            }
        }
        $this->actingAs($this->manager);

        DB::enableQueryLog();
        $this->get(route('projects.index'))->assertOk()->assertSee('Projekat 15');
        $this->assertLessThan(30, count(DB::getQueryLog()), 'Previše upita — moguć N+1.');
    }

    private function project(string $name, array $attributes = []): Project
    {
        return Project::factory()->create([...$attributes, 'name' => $name, 'status' => $attributes['status'] ?? ProjectStatus::InProgress, 'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
    }

    private function case(Project $project): AdministrativeCase
    {
        return AdministrativeCase::create([
            'project_id' => $project->id, 'name' => 'Urbanistička saglasnost',
            'case_type_id' => CaseType::firstOrCreate(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::firstOrCreate(['system_key' => 'in_preparation'], ['name' => 'U pripremi'])->id,
            'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id,
        ]);
    }

    private function item(AdministrativeCase $case, string $name, CaseItemStatus $status, array $attributes = []): AdministrativeCaseItem
    {
        return $case->items()->create([...$attributes, 'name' => $name, 'status' => $status, 'created_by' => $this->manager->id]);
    }
}
