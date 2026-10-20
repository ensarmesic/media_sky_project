<?php

namespace Tests\Feature;

use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use App\Services\AdministrativeCaseItemService;
use App\Services\AdministrativeCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdministrativeCaseItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_weighted_progress_excludes_not_applicable_items(): void
    {
        [$case, $user] = $this->caseAndUser();
        $service = app(AdministrativeCaseItemService::class);
        $service->create($case, ['name' => 'Idejni projekat', 'progress_weight' => 3, 'status' => CaseItemStatus::Completed], $user);
        $service->create($case, ['name' => 'Saglasnost EPBiH', 'progress_weight' => 1, 'status' => CaseItemStatus::NotStarted], $user);
        $service->create($case, ['name' => 'TXTV', 'progress_weight' => 10, 'status' => CaseItemStatus::Cancelled], $user);

        $this->assertSame(75, $case->fresh()->progressPercentage());
    }

    public function test_status_change_is_recorded_and_completion_can_be_reopened(): void
    {
        [$case, $user] = $this->caseAndUser();
        $service = app(AdministrativeCaseItemService::class);
        $item = $service->create($case, ['name' => 'Predaja zahtjeva'], $user);
        $service->changeStatus($item, CaseItemStatus::Completed, $user);
        $this->assertNotNull($item->fresh()->completed_at);
        $service->changeStatus($item->fresh(), CaseItemStatus::InPreparation, $user);
        $this->assertNull($item->fresh()->completed_at);
        $statusChanges = $item->activityLog()->where('event_type', 'task.status')->get();
        $this->assertSame([['completed', 'in_preparation']], $statusChanges->skip(1)->map(fn ($activity) => [$activity->metadata['old'], $activity->metadata['new']])->values()->all());
        $this->assertSame('not_started', $statusChanges->first()->metadata['old']);
    }

    public function test_required_final_step_prevents_case_closure(): void
    {
        [$case, $user] = $this->caseAndUser();
        $resolved = CaseStatus::create(['name' => 'Završeno', 'system_key' => 'resolved', 'position' => 2]);
        app(AdministrativeCaseItemService::class)->create($case, ['name' => 'Izdavanje saglasnosti', 'is_required_for_closure' => true], $user);

        $this->expectException(ValidationException::class);
        app(AdministrativeCaseService::class)->update($case, ['case_status_id' => $resolved->id], $user);
    }

    public function test_case_is_automatically_completed_at_one_hundred_percent(): void
    {
        [$case, $user] = $this->caseAndUser();
        CaseStatus::create(['name' => 'Završeno', 'system_key' => 'resolved', 'color' => 'green', 'position' => 2]);
        $service = app(AdministrativeCaseItemService::class);
        $item = $service->create($case, ['name' => 'Izdavanje saglasnosti'], $user);

        $service->changeStatus($item, CaseItemStatus::Completed, $user);

        $this->assertSame('resolved', $case->fresh()->status->system_key);
        $this->assertNotNull($case->fresh()->closed_at);
    }

    private function caseAndUser(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $status = CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'preparation', 'position' => 1]);
        $type = CaseType::create(['name' => 'Saglasnost']);
        $case = AdministrativeCase::create(['project_id' => $project->id, 'name' => 'Urbanistička saglasnost', 'case_type_id' => $type->id, 'responsible_user_id' => $user->id, 'started_at' => now(), 'case_status_id' => $status->id, 'created_by' => $user->id]);

        return [$case, $user];
    }
}
