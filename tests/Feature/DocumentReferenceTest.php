<?php

namespace Tests\Feature;

use App\Enums\DocumentDirection;
use App\Enums\DocumentSyncStatus;
use App\Models\DocumentReference;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentReferenceTest extends TestCase
{
    use RefreshDatabase;

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
