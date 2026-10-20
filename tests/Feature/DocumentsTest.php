<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Dokumenti: upload, validacija, verzije, pregled/preuzimanje s provjerom pristupa, brisanje. */
class DocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Project $project;

    private AdministrativeCaseItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->create(['role' => UserRole::ProjectManager]);
        $this->project = Project::factory()->create(['responsible_user_id' => $this->manager->id, 'created_by' => $this->manager->id]);
        $case = AdministrativeCase::create([
            'project_id' => $this->project->id, 'name' => 'Urbanistička saglasnost',
            'case_type_id' => CaseType::create(['name' => 'Saglasnost'])->id,
            'case_status_id' => CaseStatus::create(['name' => 'U pripremi', 'system_key' => 'in_preparation'])->id,
            'responsible_user_id' => $this->manager->id, 'started_at' => now(), 'created_by' => $this->manager->id,
        ]);
        $this->item = $case->items()->create(['name' => 'Saglasnost EPBiH', 'created_by' => $this->manager->id]);
    }

    public function test_multiple_files_with_metadata_are_uploaded_to_an_item(): void
    {
        $this->actingAs($this->manager);
        $component = Livewire::test('documents', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->set('meta.direction', 'outgoing')->set('meta.protocol_number', '03-23-456/26')->set('meta.document_date', '2026-04-22');

        foreach (['zahtjev.pdf', 'situacija.dwg'] as $name) {
            $component->set('upload', UploadedFile::fake()->create($name, 200))->call('saveUpload')->assertHasNoErrors();
        }

        $documents = $this->item->documents()->orderBy('id')->get();
        $this->assertSame(['zahtjev.pdf', 'situacija.dwg'], $documents->pluck('name')->all());
        $this->assertSame('outgoing', $documents->first()->direction);
        $this->assertSame('03-23-456/26', $documents->first()->protocol_number);
        $this->assertSame($this->project->id, $documents->first()->project_id);
        Storage::disk('local')->assertExists($documents->first()->path);
        $component->assertSee('Br. 03-23-456/26');
    }

    public function test_upload_validation_rejects_wrong_type_and_too_large_files(): void
    {
        config(['projectflow.documents.max_size' => 1024]);
        $this->actingAs($this->manager);

        Livewire::test('documents', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->set('upload', UploadedFile::fake()->create('skripta.exe', 10))->call('saveUpload')
            ->assertHasErrors(['upload' => 'extensions'])
            ->set('upload', UploadedFile::fake()->create('veliki.pdf', 2048))->call('saveUpload')
            ->assertHasErrors(['upload' => 'max']);

        $this->assertDatabaseCount('project_documents', 0);
    }

    public function test_new_version_keeps_previous_version(): void
    {
        $this->actingAs($this->manager);
        $component = Livewire::test('documents', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->set('upload', UploadedFile::fake()->create('plan.pdf', 50))->call('saveUpload');
        $original = ProjectDocument::firstOrFail();

        $component->set('versionUpload', UploadedFile::fake()->create('plan-v2.pdf', 60))->call('saveVersion', $original->id)->assertHasNoErrors()->assertSee('Verzija 2');

        $latest = ProjectDocument::whereNull('replaced_by_id')->firstOrFail();
        $this->assertSame(2, $latest->version);
        $this->assertSame($latest->id, $original->fresh()->replaced_by_id);
        $this->assertSame([$original->id], $latest->versionHistory()->modelKeys());
        Storage::disk('local')->assertExists($original->path);
    }

    public function test_foreign_document_cannot_be_downloaded_or_viewed(): void
    {
        $document = $this->storeDocument();
        $outsider = User::factory()->create(['role' => UserRole::TeamMember]);

        $this->actingAs($outsider)->get(route('documents.download', $document))->assertForbidden();
        $this->actingAs($outsider)->get(route('documents.show', $document))->assertForbidden();
        $this->actingAs($this->manager)->get(route('documents.download', $document))->assertOk()->assertDownload('zahtjev.pdf');
        $this->actingAs($this->manager)->get(route('documents.show', $document))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_only_author_or_manager_can_delete_and_files_are_removed(): void
    {
        $document = $this->storeDocument();
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        $this->project->members()->attach($member, ['role' => 'member']);

        $this->assertFalse($member->can('delete', $document));
        $this->assertTrue($this->manager->can('delete', $document));

        $this->actingAs($this->manager);
        Livewire::test('documents', ['ownerType' => 'item', 'ownerId' => $this->item->id])->call('delete', $document->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('project_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->path);
    }

    public function test_project_documents_page_filters_by_direction_and_protocol(): void
    {
        $this->storeDocument('zahtjev.pdf', ['direction' => 'outgoing', 'protocol_number' => '03-23-456/26']);
        $this->storeDocument('dopis.pdf', ['direction' => 'incoming', 'protocol_number' => '02-15-321/26']);
        $this->actingAs($this->manager);

        Livewire::test('pages::projects.documents', ['project' => $this->project])
            ->assertSee('zahtjev.pdf')->assertSee('dopis.pdf')
            ->set('direction', 'incoming')->assertSee('dopis.pdf')->assertDontSee('zahtjev.pdf')
            ->set('direction', '')->set('search', '03-23')->assertSee('zahtjev.pdf')->assertDontSee('dopis.pdf');
    }

    public function test_case_drawer_uploads_to_case_and_lists_documents_from_items(): void
    {
        $this->storeDocument('dokument-stavke.pdf');
        $case = $this->item->administrativeCase;
        $this->actingAs($this->manager);

        Livewire::test('documents', ['ownerType' => 'case', 'ownerId' => $case->id])
            ->set('upload', UploadedFile::fake()->create('rjesenje.pdf', 40))->call('saveUpload')->assertHasNoErrors();

        Livewire::test('case-drawer')
            ->call('open', $case->id)
            ->assertSee('Urbanistička saglasnost')
            ->assertSee('rjesenje.pdf')
            ->assertSee('dokument-stavke.pdf')
            ->assertSee('Stavka: Saglasnost EPBiH');

        $this->assertSame(1, $case->documents()->count());
    }

    public function test_project_level_upload_and_delete_from_documents_page(): void
    {
        $this->actingAs($this->manager);
        Livewire::test('documents', ['ownerType' => 'project', 'ownerId' => $this->project->id, 'showList' => false])
            ->set('upload', UploadedFile::fake()->create('ugovor.pdf', 40))->call('saveUpload')->assertHasNoErrors();
        $document = $this->project->ownDocuments()->firstOrFail();

        Livewire::test('pages::projects.documents', ['project' => $this->project])
            ->assertSee('ugovor.pdf')->assertSee('Projekat')
            ->call('delete', $document->id)
            ->assertDontSee('ugovor.pdf');
    }

    public function test_member_without_rights_cannot_upload(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $this->project->members()->attach($viewer, ['role' => 'member']);
        $this->actingAs($viewer);

        Livewire::test('documents', ['ownerType' => 'item', 'ownerId' => $this->item->id])
            ->assertDontSee('Prevucite fajlove')
            ->set('upload', UploadedFile::fake()->create('x.pdf', 10))->call('saveUpload')
            ->assertForbidden();
    }

    private function storeDocument(string $name = 'zahtjev.pdf', array $attributes = []): ProjectDocument
    {
        $path = 'project-documents/'.$this->project->id.'/'.$name;
        Storage::disk('local')->put($path, '%PDF-1.4');

        return ProjectDocument::create([...$attributes, 'project_id' => $this->project->id, 'documentable_type' => $this->item->getMorphClass(), 'documentable_id' => $this->item->id,
            'uploaded_by' => $this->manager->id, 'name' => $name, 'original_name' => $name, 'path' => $path, 'mime_type' => 'application/pdf', 'size' => 8]);
    }
}
