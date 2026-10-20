<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_upload_download_and_delete_project_document(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create();

        $this->actingAs($admin);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->set('tab', 'dokumenti')
            ->set('document', UploadedFile::fake()->create('odluka.pdf', 100, 'application/pdf'))
            ->set('documentDescription', 'Potpisana odluka')
            ->call('uploadDocument')
            ->assertHasNoErrors()
            ->assertSee('odluka.pdf');

        $document = ProjectDocument::firstOrFail();
        Storage::disk('local')->assertExists($document->path);

        $this->get(route('projects.documents.download', [$project, $document]))
            ->assertOk()
            ->assertDownload('odluka.pdf');

        Livewire::test('pages::projects.show', ['project' => $project])
            ->call('deleteDocument', $document->id)
            ->assertHasNoErrors();

        Storage::disk('local')->assertMissing($document->path);
        $this->assertDatabaseMissing('project_documents', ['id' => $document->id]);
    }

    public function test_user_cannot_download_document_from_project_they_cannot_view(): void
    {
        Storage::fake('local');
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $project = Project::factory()->create();
        Storage::disk('local')->put('project-documents/test.pdf', 'document');
        $document = ProjectDocument::create([
            'project_id' => $project->id,
            'uploaded_by' => $project->created_by,
            'name' => 'test.pdf',
            'path' => 'project-documents/test.pdf',
            'mime_type' => 'application/pdf',
            'size' => 8,
        ]);

        $this->actingAs($member)
            ->get(route('projects.documents.download', [$project, $document]))
            ->assertForbidden();
    }

    public function test_upload_rejects_unsupported_file_types(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $project = Project::factory()->create();

        $this->actingAs($admin);

        Livewire::test('pages::projects.show', ['project' => $project])
            ->set('document', UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'))
            ->call('uploadDocument')
            ->assertHasErrors(['document']);

        $this->assertDatabaseCount('project_documents', 0);
    }
}
