<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Enums\CaseProgressStatus;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Izračunata pravila modela: status postupka, napredak projekta, automatski datumi. */
class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_status_is_computed_from_item_statuses(): void
    {
        $this->assertSame(CaseProgressStatus::NotStarted, CaseProgressStatus::fromItemStatuses([]));
        $this->assertSame(CaseProgressStatus::NotStarted, CaseProgressStatus::fromItemStatuses([CaseItemStatus::NotStarted, CaseItemStatus::Cancelled]));
        $this->assertSame(CaseProgressStatus::Completed, CaseProgressStatus::fromItemStatuses([CaseItemStatus::Completed, CaseItemStatus::Cancelled]));
        $this->assertSame(CaseProgressStatus::WaitingResponse, CaseProgressStatus::fromItemStatuses([CaseItemStatus::Completed, CaseItemStatus::WaitingResponse, CaseItemStatus::NotStarted]));
        $this->assertSame(CaseProgressStatus::InProgress, CaseProgressStatus::fromItemStatuses([CaseItemStatus::Submitted, CaseItemStatus::NotStarted]));
        $this->assertSame(CaseProgressStatus::InProgress, CaseProgressStatus::fromItemStatuses([CaseItemStatus::InPreparation]));
        $this->assertSame(CaseProgressStatus::InProgress, CaseProgressStatus::fromItemStatuses([CaseItemStatus::Completed, CaseItemStatus::NotStarted]));
    }

    public function test_project_progress_ignores_cancelled_and_removed_items(): void
    {
        [$project, $case, $user] = $this->projectWithCase();
        foreach ([CaseItemStatus::Completed, CaseItemStatus::Completed, CaseItemStatus::NotStarted, CaseItemStatus::Cancelled] as $status) {
            $case->items()->create(['name' => 'Stavka', 'status' => $status, 'created_by' => $user->id]);
        }
        $case->items()->create(['name' => 'Uklonjena', 'status' => CaseItemStatus::NotStarted, 'is_active' => false, 'created_by' => $user->id]);

        $this->assertSame(67, $project->administrativeProgress());
        $this->assertSame(['done' => 2, 'total' => 3, 'percent' => 67], $case->fresh()->itemCounts());
        $this->assertNull(Project::factory()->create()->administrativeProgress());
    }

    public function test_project_progress_gives_each_case_an_equal_share(): void
    {
        [$project, $case, $user] = $this->projectWithCase();
        $addCase = fn (array $statuses, bool $archived = false) => tap(AdministrativeCase::create([...$case->only(['project_id', 'case_type_id', 'case_status_id', 'responsible_user_id', 'started_at', 'created_by']), 'name' => 'Postupak', 'is_archived' => $archived]),
            fn (AdministrativeCase $c) => collect($statuses)->each(fn (CaseItemStatus $s) => $c->items()->create(['name' => 'Stavka', 'status' => $s, 'created_by' => $user->id])));

        // 3 završena postupka (različit broj stavki) + 1 bez ijedne završene stavke = 75 %, ne 93 %.
        foreach (range(1, 10) as $i) {
            $case->items()->create(['name' => 'Stavka', 'status' => CaseItemStatus::Completed, 'created_by' => $user->id]);
        }
        $addCase([CaseItemStatus::Completed, CaseItemStatus::Completed]);
        $addCase([CaseItemStatus::Completed]);
        $addCase([CaseItemStatus::NotStarted]);
        $addCase([CaseItemStatus::NotStarted], archived: true);
        $this->assertSame(75, $project->fresh()->administrativeProgress());

        // Postupak bez stavki je neurađen (3 od 5 = 60 %); djelimično urađen nosi svoj udio (+ 1/2 od 1/6).
        $addCase([]);
        $this->assertSame(60, $project->fresh()->administrativeProgress());
        $addCase([CaseItemStatus::Completed, CaseItemStatus::NotStarted]);
        $this->assertSame(58, $project->fresh()->administrativeProgress());
    }

    public function test_status_transitions_set_submission_and_response_dates(): void
    {
        [, $case, $user] = $this->projectWithCase();
        $item = $case->items()->create(['name' => 'Saglasnost EPBiH', 'created_by' => $user->id]);

        $item->update(['status' => CaseItemStatus::Submitted]);
        $this->assertTrue($item->fresh()->submitted_at->isToday());

        $item->update(['submitted_at' => today()->subDays(12), 'status' => CaseItemStatus::WaitingResponse]);
        $this->assertSame(12, $item->fresh()->daysWaiting());

        $item->update(['status' => CaseItemStatus::Completed]);
        $this->assertTrue($item->fresh()->response_at->isToday());
        $this->assertNotNull($item->fresh()->completed_at);
        $this->assertSame(today()->subDays(12)->toDateString(), $item->fresh()->submitted_at->toDateString());
    }

    public function test_overdue_means_past_due_date_and_not_closed(): void
    {
        [, $case, $user] = $this->projectWithCase();
        $make = fn (CaseItemStatus $status, int $days) => $case->items()->create(['name' => 'S', 'status' => $status, 'due_date' => today()->addDays($days), 'created_by' => $user->id]);

        $this->assertTrue($make(CaseItemStatus::InPreparation, -1)->isOverdue());
        $this->assertFalse($make(CaseItemStatus::InPreparation, 0)->isOverdue());
        $this->assertFalse($make(CaseItemStatus::Completed, -5)->isOverdue());
        $this->assertFalse($make(CaseItemStatus::Cancelled, -5)->isOverdue());
    }

    /** @return array{0: Project, 1: AdministrativeCase, 2: User} */
    private function projectWithCase(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $case = AdministrativeCase::create([
            'project_id' => $project->id, 'name' => 'Urbanistička saglasnost',
            'case_type_id' => CaseType::create(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $user->id, 'started_at' => now(), 'created_by' => $user->id,
        ]);

        return [$project, $case, $user];
    }
}
