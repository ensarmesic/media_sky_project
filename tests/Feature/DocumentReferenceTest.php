<?php

namespace Tests\Feature;

use App\Enums\DocumentDirection;
use App\Enums\DocumentSyncStatus;
use App\Enums\UserRole;
use App\Models\DocumentReference;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_document_tab_displays_and_filters_protocol_references(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create();
        DocumentReference::create([
            'project_id' => $project->id,
            'external_system' => 'Interni protokol',
            'external_document_id' => 'DOC-100',
            'protocol_number' => 'UP-01/26',
            'title' => 'Urbanistička saglasnost',
            'document_type' => 'Saglasnost',
            'direction' => DocumentDirection::Incoming,
            'document_date' => '2026-10-10',
            'sync_status' => DocumentSyncStatus::Synced,
        ]);
        DocumentReference::create([
            'project_id' => $project->id,
            'external_system' => 'Interni protokol',
            'external_document_id' => 'DOC-101',
            'protocol_number' => 'ZA-02/26',
            'title' => 'Zahtjev za mišljenje',
            'document_type' => 'Zahtjev',
            'direction' => DocumentDirection::Outgoing,
            'document_date' => '2026-10-15',
            'sync_status' => DocumentSyncStatus::Synced,
        ]);

        $this->actingAs($admin);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->set('tab', 'dokumenti')
            ->assertSee('Urbanistička saglasnost')
            ->assertSee('Zahtjev za mišljenje')
            ->set('documentSearch', 'UP-01')
            ->assertSee('Urbanistička saglasnost')
            ->assertDontSee('Zahtjev za mišljenje');
    }

    public function test_external_document_identity_is_unique_per_system(): void
    {
        $project = Project::factory()->create();
        $data = [
            'project_id' => $project->id,
            'external_system' => 'protocol',
            'external_document_id' => '55',
            'title' => 'Dokument',
            'direction' => DocumentDirection::Internal,
            'sync_status' => DocumentSyncStatus::Pending,
        ];

        DocumentReference::create($data);

        $this->expectException(UniqueConstraintViolationException::class);
        DocumentReference::create($data);
    }
}
