<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Institution;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/** Izvoz projekta u Excel: listovi, redovi, filter, kašnjenje, naziv fajla i autorizacija. */
class ExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00');
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager, 'name' => 'Ensar Mešić']);
        $this->project = Project::factory()->create(['code' => 'FTTH/BAN 1', 'name' => 'FTTH Banovići', 'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $this->project->members()->attach($this->manager, ['role' => 'manager']);
        $epbih = Institution::create(['name' => 'JP EPBiH']);
        $type = CaseType::create(['name' => 'Saglasnost'])->id;
        $status = CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id;

        $waiting = AdministrativeCase::create(['project_id' => $this->project->id, 'name' => 'Saglasnost EPBiH', 'institution_id' => $epbih->id, 'case_type_id' => $type, 'case_status_id' => $status, 'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id]);
        $waiting->items()->create(['name' => 'Zahtjev EPBiH', 'status' => CaseItemStatus::WaitingResponse, 'institution_id' => $epbih->id, 'submitted_at' => '2026-10-09', 'due_date' => '2026-10-15', 'responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $done = AdministrativeCase::create(['project_id' => $this->project->id, 'name' => 'Idejni projekat', 'case_type_id' => $type, 'case_status_id' => $status, 'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id]);
        $done->items()->create(['name' => 'Štampa projekta', 'status' => CaseItemStatus::Completed, 'created_by' => $this->manager->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, list<list<mixed>>> */
    private function read(BinaryFileResponse $response): array
    {
        $reader = new Reader;
        $reader->open($response->getFile()->getPathname());
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        return $sheets;
    }

    public function test_project_export_contains_sheets_rows_dates_and_overdue_flag(): void
    {
        $response = $this->actingAs($this->manager)->get(route('projects.export.xlsx', $this->project));

        $response->assertOk();
        $this->assertSame('attachment; filename=FTTH-BAN-1_21-10-2026.xlsx', $response->headers->get('content-disposition'));
        $sheets = $this->read($response->baseResponse);

        $this->assertSame(['Postupci', 'Stavke', 'Dokumenti'], array_keys($sheets));
        $this->assertCount(3, $sheets['Postupci']); // zaglavlje + 2 postupka
        $this->assertSame(['Saglasnost EPBiH', 'JP EPBiH', 1, 0, 0, 'Čeka odgovor'], array_slice($sheets['Postupci'][1], 0, 6));

        $item = $sheets['Stavke'][1];
        $this->assertSame(['FTTH Banovići', 'Saglasnost EPBiH', 1, 'Zahtjev EPBiH', 'Čeka odgovor', 'JP EPBiH'], array_slice($item, 0, 6));
        $this->assertInstanceOf(\DateTimeInterface::class, $item[6]);
        $this->assertSame('15.10.2026', $item[6]->format('d.m.Y'));
        $this->assertSame(12, $item[9]);
        $this->assertSame(['Ensar Mešić', 0, 'Da'], array_slice($item, 10, 3));
        $this->assertSame('Ne', $sheets['Stavke'][2][12]);
    }

    public function test_export_respects_active_case_filter(): void
    {
        $response = $this->actingAs($this->manager)->get(route('projects.export.xlsx', ['project' => $this->project, 'status' => 'completed']));

        $sheets = $this->read($response->baseResponse);
        $this->assertCount(2, $sheets['Postupci']);
        $this->assertSame('Idejni projekat', $sheets['Postupci'][1][0]);
        $this->assertCount(2, $sheets['Stavke']);
        $this->assertSame('Štampa projekta', $sheets['Stavke'][1][3]);
    }

    public function test_user_without_access_gets_403_and_invalid_filter_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::TeamMember]))
            ->get(route('projects.export.xlsx', $this->project))->assertForbidden();

        $this->actingAs($this->manager)
            ->get(route('projects.export.xlsx', ['project' => $this->project, 'status' => 'nesto']))->assertSessionHasErrors('status');
    }

    public function test_project_page_links_to_pdf_and_excel_with_current_filter(): void
    {
        $this->actingAs($this->manager)->get(route('projects.show', $this->project))
            ->assertSee(route('projects.report.pdf', $this->project))
            ->assertSee(route('projects.export.xlsx', ['project' => $this->project, 'status' => 'all']));
    }
}
