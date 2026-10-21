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
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\InstitutionOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/** Pregled po instituciji: autorizacija, grupisanje, sortiranje, kontakt, izvoz i "Kopiraj listu". */
class InstitutionsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Institution $epbih;

    private Institution $opcina;

    private AdministrativeCase $myCase;

    private AdministrativeCase $foreignCase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00');
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager, 'name' => 'Ensar Mešić']);
        $this->epbih = Institution::create(['name' => 'JP EPBiH', 'address' => 'Vilsonovo šetalište 15, Sarajevo', 'phone' => '+387 33 751 000']);
        $this->opcina = Institution::create(['name' => 'Općina Banovići']);

        $mine = Project::factory()->create(['name' => 'FTTH Banovići', 'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $mine->members()->attach($this->manager, ['role' => 'manager']);
        $foreign = Project::factory()->create(['name' => 'Tuđi projekat']);
        $this->myCase = $this->case($mine, 'Saglasnosti');
        $this->foreignCase = $this->case($foreign, 'Tuđi postupak');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function case(Project $project, string $name): AdministrativeCase
    {
        return AdministrativeCase::create([
            'project_id' => $project->id, 'name' => $name,
            'case_type_id' => (CaseType::first() ?? CaseType::create(['name' => 'Saglasnost']))->id,
            'case_status_id' => (CaseStatus::first() ?? CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation']))->id,
            'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id,
        ]);
    }

    private function item(AdministrativeCase $case, string $name, CaseItemStatus $status, array $attributes = []): AdministrativeCaseItem
    {
        return $case->items()->create(['name' => $name, 'status' => $status, 'institution_id' => $this->epbih->id, 'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id, ...$attributes]);
    }

    public function test_detail_groups_only_items_from_my_projects(): void
    {
        $this->item($this->myCase, 'Čeka 5 dana', CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-10-16']);
        $this->item($this->myCase, 'Čeka 20 dana', CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-10-01']);
        $this->item($this->myCase, 'Predato juče', CaseItemStatus::Submitted, ['submitted_at' => '2026-10-20']);
        $this->item($this->myCase, 'Kasni priprema', CaseItemStatus::InPreparation, ['due_date' => '2026-10-10']);
        $this->item($this->myCase, 'Za predaju', CaseItemStatus::NotStarted, ['due_date' => '2026-11-10']);
        $this->item($this->myCase, 'Dopuna', CaseItemStatus::SupplementRequired);
        $this->item($this->myCase, 'Završena', CaseItemStatus::Completed);
        $this->item($this->foreignCase, 'Tuđa stavka', CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-09-01']);

        $groups = app(InstitutionOverview::class)->groups($this->epbih, $this->manager);

        $this->assertSame(['Čeka 20 dana', 'Čeka 5 dana', 'Predato juče'], $groups['waiting']->pluck('name')->all());
        $this->assertSame(['Kasni priprema'], $groups['overdue']->pluck('name')->all());
        $this->assertSame(['Za predaju'], $groups['to_submit']->pluck('name')->all());
        $this->assertSame(['Dopuna'], $groups['supplement']->pluck('name')->all());

        $this->actingAs($this->manager)->get(route('institutions.show', $this->epbih))
            ->assertOk()
            ->assertSeeInOrder(['Čeka odgovor', 'Čeka 20 dana', 'Čeka 5 dana', 'Kasni', 'Kasni priprema', 'Za predaju', 'Potrebna dopuna', 'Dopuna'])
            ->assertSee('Vilsonovo šetalište 15, Sarajevo')
            ->assertDontSee('Tuđa stavka')->assertDontSee('Tuđi projekat');
    }

    public function test_list_counts_only_visible_items_and_sorts_by_attention(): void
    {
        $this->item($this->myCase, 'Kasni', CaseItemStatus::InPreparation, ['due_date' => '2026-10-10']);
        $this->item($this->myCase, 'Čeka', CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-10-11']);
        $this->item($this->foreignCase, 'Tuđa', CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-09-01']);
        $this->item($this->myCase, 'Općina', CaseItemStatus::NotStarted, ['institution_id' => $this->opcina->id]);

        $rows = app(InstitutionOverview::class)->summaries($this->manager);

        $this->assertSame(['JP EPBiH', 'Općina Banovići'], $rows->pluck('institution.name')->all());
        $this->assertSame(['open' => 2, 'waiting' => 1, 'overdue' => 1, 'longest' => 10], collect($rows[0])->except('institution')->all());

        Livewire::actingAs($this->manager)->test('pages::institutions.index')
            ->assertSeeInOrder(['JP EPBiH', 'Općina Banovići'])
            ->set('search', 'Općina')->assertSee('Općina Banovići')->assertDontSee('JP EPBiH');
    }

    public function test_query_count_does_not_grow_with_items(): void
    {
        $count = function (): int {
            app()->forgetInstance(InstitutionOverview::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->manager)->get(route('institutions.show', $this->epbih))->assertOk();

            return count(DB::getQueryLog());
        };
        $this->item($this->myCase, 'Prva', CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-10-01']);
        $few = $count();
        foreach (range(1, 8) as $i) {
            $this->item($this->case(Project::factory()->create(['responsible_user_id' => $this->manager->id]), "Postupak {$i}"), "Stavka {$i}", CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-10-02']);
        }

        $this->assertSame($few, $count());
    }

    public function test_contact_can_be_edited_by_manager_but_not_team_member(): void
    {
        Livewire::actingAs($this->manager)->test('pages::institutions.show', ['institution' => $this->epbih])
            ->call('startEditing')
            ->set('contact.email', 'protokol@epbih.ba')->set('contact.contact_person', 'Amra H.')
            ->call('saveContact')->assertHasNoErrors();
        $this->assertSame('protokol@epbih.ba', $this->epbih->refresh()->email);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::TeamMember]))->test('pages::institutions.show', ['institution' => $this->epbih])
            ->call('startEditing')->assertForbidden();
    }

    public function test_copy_list_contains_waiting_items_with_submission_date_and_protocol(): void
    {
        $item = $this->item($this->myCase, 'Zahtjev za saglasnost', CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-10-09']);
        ProjectDocument::create(['project_id' => $this->myCase->project_id, 'uploaded_by' => $this->manager->id, 'name' => 'Zahtjev', 'path' => 'x.pdf', 'size' => 1, 'version' => 1, 'direction' => 'outgoing', 'protocol_number' => '03-23-456/26', 'documentable_type' => $item->getMorphClass(), 'documentable_id' => $item->id]);

        $text = Livewire::actingAs($this->manager)->test('pages::institutions.show', ['institution' => $this->epbih])->get('waitingText');

        $this->assertStringContainsString('Stavke koje čekaju odgovor – JP EPBiH', $text);
        $this->assertStringContainsString('- FTTH Banovići – Zahtjev za saglasnost (predato 09.10.2026., broj protokola 03-23-456/26)', $text);
    }

    public function test_export_contains_only_visible_items_with_group_column(): void
    {
        $this->item($this->myCase, 'Moja stavka', CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-10-11']);
        $this->item($this->foreignCase, 'Tuđa stavka', CaseItemStatus::WaitingResponse, ['submitted_at' => '2026-10-11']);

        $response = $this->actingAs($this->manager)->get(route('institutions.export.xlsx', $this->epbih));
        $response->assertOk();
        $this->assertSame('attachment; filename=JP-EPBiH_21-10-2026.xlsx', $response->headers->get('content-disposition'));

        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertCount(2, $rows);
        $this->assertSame(['Grupa', 'Projekat'], array_slice($rows[0], 0, 2));
        $this->assertSame(['Čeka odgovor', 'FTTH Banovići', 'Saglasnosti', 1, 'Moja stavka'], array_slice($rows[1], 0, 5));
    }

    public function test_status_can_be_changed_from_institution_page_only_when_allowed(): void
    {
        $mine = $this->item($this->myCase, 'Moja', CaseItemStatus::InPreparation);
        $foreign = $this->item($this->foreignCase, 'Tuđa', CaseItemStatus::InPreparation);

        Livewire::actingAs($this->manager)->test('pages::institutions.show', ['institution' => $this->epbih])
            ->call('changeItemStatus', $mine->id, CaseItemStatus::Submitted->value)->assertHasNoErrors();
        $this->assertSame(CaseItemStatus::Submitted, $mine->refresh()->status);

        $foreign->update(['responsible_user_id' => User::factory()->create()->id]);
        Livewire::actingAs($this->manager)->test('pages::institutions.show', ['institution' => $this->epbih])
            ->call('changeItemStatus', $foreign->id, CaseItemStatus::Completed->value)->assertForbidden();
    }

    public function test_project_items_table_links_to_institution_and_sidebar_has_entry(): void
    {
        $this->item($this->myCase, 'Saglasnost EPBiH', CaseItemStatus::InPreparation);

        $this->actingAs($this->manager)->get(route('projects.show', $this->myCase->project_id))
            ->assertSee(route('institutions.show', $this->epbih))
            ->assertSee(route('institutions.index'));
    }
}
