<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_report_is_downloaded_as_pdf(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create();

        $this->actingAs($admin)
            ->get(route('projects.report.pdf', $project))
            ->assertOk()
            ->assertDownload('projekat-'.$project->code.'.pdf')
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_user_cannot_download_pdf_for_inaccessible_project(): void
    {
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $project = Project::factory()->create();

        $this->actingAs($member)->get(route('projects.report.pdf', $project))->assertForbidden();
    }
}
