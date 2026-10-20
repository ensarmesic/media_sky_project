<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Comment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Glavni prikaz /projekti: projekat → kartice predmeta → aktivni zadaci. */
class ProjectsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Project $project;

    private AdministrativeCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager, 'name' => 'Amra Hodžić']);
        $this->project = Project::factory()->create(['name' => 'FTTH Lukavac', 'location' => 'Lukavac', 'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $this->case = $this->makeCase($this->project, 'Urbanistička saglasnost');
    }

    public function test_tasks_are_grouped_by_project_and_case_and_closed_tasks_are_hidden(): void
    {
        $this->task('Saglasnost Vodovoda', CaseItemStatus::InProgress);
        $this->task('Idejni projekat', CaseItemStatus::Completed);
        $this->task('Otkazana stavka', CaseItemStatus::Cancelled);

        $this->actingAs($this->manager)->get(route('projects.index'))
            ->assertOk()
            ->assertSeeInOrder(['FTTH Lukavac', 'Lukavac', 'Urbanistička saglasnost', 'Saglasnost Vodovoda'])
            ->assertSee('1/2 završeno')
            ->assertDontSee('Idejni projekat')
            ->assertDontSee('Otkazana stavka');
    }

    public function test_cases_without_active_tasks_are_shown_only_with_toggle(): void
    {
        $this->task('Gotov zadatak', CaseItemStatus::Completed);
        $this->actingAs($this->manager);

        Livewire::test('pages::projects.index')
            ->assertDontSee('Urbanistička saglasnost')
            ->assertSee('Nema aktivnih zadataka')
            ->set('showCompleted', true)
            ->assertSee('Urbanistička saglasnost');
    }

    public function test_phase_overdue_mine_project_and_search_filters(): void
    {
        $other = User::factory()->create();
        $this->task('U toku zadatak', CaseItemStatus::InProgress, ['responsible_user_id' => $this->manager->id]);
        $this->task('Na čekanju zadatak', CaseItemStatus::Waiting, ['responsible_user_id' => $other->id]);
        $this->task('Zakašnjeli zadatak', CaseItemStatus::New, ['due_date' => today()->subDay()]);
        $otherProject = Project::factory()->create(['name' => 'Vodovod Centar', 'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $this->task('Zadatak drugog projekta', CaseItemStatus::New, [], $this->makeCase($otherProject, 'Okolinska dozvola'));
        $this->actingAs($this->manager);

        Livewire::test('pages::projects.index')
            ->set('phase', CaseItemStatus::Waiting->value)
            ->assertSee('Na čekanju zadatak')->assertDontSee('U toku zadatak')
            ->set('phase', 'overdue')
            ->assertSee('Zakašnjeli zadatak')->assertDontSee('Na čekanju zadatak')
            ->set('phase', '')->set('onlyMine', true)
            ->assertSee('U toku zadatak')->assertDontSee('Na čekanju zadatak')
            ->set('onlyMine', false)->set('projectId', (string) $otherProject->id)
            ->assertSee('Zadatak drugog projekta')->assertDontSee('U toku zadatak')
            ->set('projectId', '')->set('search', 'okolinska')
            ->assertSee('Zadatak drugog projekta')->assertDontSee('U toku zadatak');
    }

    public function test_filters_are_read_from_url_query(): void
    {
        $this->task('Na pregledu zadatak', CaseItemStatus::InReview);
        $this->task('Novi zadatak', CaseItemStatus::New);

        $this->actingAs($this->manager)
            ->get(route('projects.index', ['faza' => CaseItemStatus::InReview->value, 'q' => 'pregledu']))
            ->assertOk()
            ->assertSee('Na pregledu zadatak')
            ->assertDontSee('Novi zadatak');
    }

    public function test_quick_add_creates_new_task_in_case(): void
    {
        $this->task('Postojeći', CaseItemStatus::InProgress);
        $this->actingAs($this->manager);

        Livewire::test('pages::projects.index')
            ->set("quickTask.{$this->case->id}", 'Brzo dodan zadatak')
            ->call('addTask', $this->case->id)
            ->assertHasNoErrors()
            ->assertSee('Brzo dodan zadatak');

        $this->assertSame(CaseItemStatus::New, $this->case->items()->where('name', 'Brzo dodan zadatak')->firstOrFail()->status);
    }

    public function test_viewer_cannot_quick_add_tasks(): void
    {
        $this->task('Postojeći', CaseItemStatus::InProgress);
        $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));

        Livewire::test('pages::projects.index')
            ->set("quickTask.{$this->case->id}", 'Nedozvoljeno')
            ->call('addTask', $this->case->id)
            ->assertForbidden();
    }

    public function test_page_uses_constant_number_of_queries_with_many_tasks(): void
    {
        foreach (range(1, 3) as $caseNumber) {
            $case = $caseNumber === 1 ? $this->case : $this->makeCase($this->project, 'Predmet '.$caseNumber);
            foreach (range(1, 20) as $taskNumber) {
                $item = $this->task("Zadatak $caseNumber-$taskNumber", CaseItemStatus::InProgress, ['responsible_user_id' => User::factory()->create()->id], $case);
                Comment::create(['commentable_type' => $item->getMorphClass(), 'commentable_id' => $item->id, 'user_id' => $this->manager->id, 'body' => 'Komentar']);
            }
        }
        $this->actingAs($this->manager);

        DB::enableQueryLog();
        $this->get(route('projects.index'))->assertOk()->assertSee('Zadatak 3-20');
        $queries = count(DB::getQueryLog());

        $this->assertLessThan(25, $queries, "Previše upita ($queries) za 60 zadataka — moguć N+1.");
    }

    private function makeCase(Project $project, string $name): AdministrativeCase
    {
        return AdministrativeCase::create([
            'project_id' => $project->id, 'name' => $name,
            'case_type_id' => CaseType::firstOrCreate(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::firstOrCreate(['system_key' => 'in_preparation'], ['name' => 'U pripremi'])->id,
            'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id,
        ]);
    }

    private function task(string $name, CaseItemStatus $status, array $attributes = [], ?AdministrativeCase $case = null): AdministrativeCaseItem
    {
        return ($case ?? $this->case)->items()->create([...$attributes, 'name' => $name, 'status' => $status, 'created_by' => $this->manager->id]);
    }
}
