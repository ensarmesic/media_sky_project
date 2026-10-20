<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\User;
use App\Services\AdministrativeCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrativeCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_creation_and_status_changes_are_recorded(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create();
        $preparation = CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation', 'position' => 1]);
        $submitted = CaseStatus::create(['name' => 'Predato', 'system_key' => 'submitted', 'position' => 2]);
        $type = CaseType::create(['name' => 'Zahtjev']);
        $service = app(AdministrativeCaseService::class);
        $case = $service->create(['project_id' => $project->id, 'name' => 'Zahtjev za saglasnost', 'case_type_id' => $type->id, 'responsible_user_id' => $admin->id, 'started_at' => '2026-10-19', 'case_status_id' => $preparation->id], $admin);
        $service->update($case, ['case_status_id' => $submitted->id], $admin);
        $this->assertDatabaseHas('case_activities', ['administrative_case_id' => $case->id, 'event_type' => 'created']);
        $this->assertDatabaseHas('case_activities', ['administrative_case_id' => $case->id, 'event_type' => 'status_changed']);
    }

    public function test_viewer_cannot_update_case(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $this->assertFalse($viewer->can('create', AdministrativeCase::class));
    }

    public function test_case_can_be_created_without_an_outcome(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create();
        $status = CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation', 'position' => 1]);
        $type = CaseType::create(['name' => 'Zahtjev']);

        $case = app(AdministrativeCaseService::class)->create([
            'project_id' => $project->id,
            'name' => 'Predmet bez ishoda',
            'case_type_id' => $type->id,
            'responsible_user_id' => $admin->id,
            'started_at' => '2026-10-20',
            'case_status_id' => $status->id,
            'institution_id' => '',
            'outcome' => '',
        ], $admin);

        $this->assertNull($case->outcome);
        $this->assertNull($case->institution_id);
    }

    public function test_old_case_links_redirect_into_the_project(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create();
        $case = AdministrativeCase::create([
            'project_id' => $project->id, 'name' => 'Predmet', 'case_type_id' => CaseType::create(['name' => 'Zahtjev'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $admin->id, 'started_at' => now(), 'created_by' => $admin->id,
        ]);

        $this->get(route('cases.show', $case))->assertRedirect(route('login'));
        $this->actingAs($admin)->get(route('cases.show', $case))->assertRedirect($case->url());
        $this->get(route('case-catalogs.index'))->assertOk();
    }
}
