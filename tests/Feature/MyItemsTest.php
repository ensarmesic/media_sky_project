<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Moje stavke: grupisanje po hitnosti i brza promjena statusa. */
class MyItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AdministrativeCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00'); // srijeda
        $this->user = User::factory()->create(['role' => UserRole::TeamMember]);
        $project = Project::factory()->create(['name' => 'FTTH Banovići']);
        $project->members()->attach($this->user, ['role' => 'member']);
        $this->case = AdministrativeCase::create([
            'project_id' => $project->id, 'name' => 'Urbanistička saglasnost',
            'case_type_id' => CaseType::create(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $this->user->id, 'started_at' => now(), 'created_by' => $this->user->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(string $name, array $attributes = []): AdministrativeCaseItem
    {
        return $this->case->items()->create(['name' => $name, 'status' => CaseItemStatus::InPreparation, 'responsible_user_id' => $this->user->id, 'created_by' => $this->user->id, ...$attributes]);
    }

    public function test_items_are_grouped_by_urgency(): void
    {
        $this->item('Kasni stavka', ['due_date' => '2026-10-20']);
        $this->item('Čeka stavka', ['status' => CaseItemStatus::WaitingResponse, 'submitted_at' => '2026-10-01', 'due_date' => '2026-11-30']);
        $this->item('Sedmica stavka', ['due_date' => '2026-10-25']);
        $this->item('Kasnije stavka', ['due_date' => '2026-10-27']);
        $this->item('Bez roka stavka');
        $this->item('Završena stavka', ['status' => CaseItemStatus::Completed]);
        $this->case->items()->create(['name' => 'Tuđa stavka', 'responsible_user_id' => User::factory()->create()->id, 'created_by' => $this->user->id]);

        $groups = Livewire::actingAs($this->user)->test('pages::my-tasks.index')
            ->assertSeeInOrder(['Kasni stavka', 'Čeka stavka', 'čeka 20 dana', 'Sedmica stavka', 'Kasnije stavka', 'Bez roka stavka'])
            ->assertDontSee('Završena stavka')->assertDontSee('Tuđa stavka')
            ->get('groups');

        $this->assertSame(['overdue', 'waiting', 'this_week', 'later', 'no_deadline'], $groups->keys()->all());
        $this->assertSame(['Kasni stavka'], $groups['overdue']->pluck('name')->all());
        $this->assertSame(['Sedmica stavka'], $groups['this_week']->pluck('name')->all());
    }

    public function test_status_can_be_changed_from_the_list(): void
    {
        $item = $this->item('Zahtjev EPBiH', ['due_date' => '2026-10-24']);

        Livewire::actingAs($this->user)->test('pages::my-tasks.index')
            ->call('changeItemStatus', $item->id, CaseItemStatus::Submitted->value)
            ->assertSee('Predato')
            ->call('changeItemStatus', $item->id, CaseItemStatus::Completed->value)
            ->assertDontSee('Zahtjev EPBiH');

        $this->assertSame(CaseItemStatus::Completed, $item->refresh()->status);
        $this->assertNotNull($item->submitted_at);
    }

    public function test_cannot_change_status_of_someone_elses_item(): void
    {
        $other = User::factory()->create(['role' => UserRole::TeamMember]);
        $item = $this->item('Moja stavka');

        Livewire::actingAs($other)->test('pages::my-tasks.index')
            ->call('changeItemStatus', $item->id, CaseItemStatus::Completed->value)
            ->assertForbidden();
    }

    public function test_search_filters_items(): void
    {
        $this->item('Saglasnost EPBiH');
        $this->item('Dozvola općine');

        Livewire::actingAs($this->user)->test('pages::my-tasks.index')
            ->set('search', 'EPBiH')
            ->assertSee('Saglasnost EPBiH')->assertDontSee('Dozvola općine');
    }
}
