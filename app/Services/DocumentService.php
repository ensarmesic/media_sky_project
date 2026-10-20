<?php

namespace App\Services;

use App\Enums\DocumentDirection;
use App\Enums\DocumentSyncStatus;
use App\Enums\NotificationKind;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\DocumentReference;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    /** Način A — direktni upload datoteke u privatni storage. */
    public function storeFile(Project $project, User $user, UploadedFile $file, array $data = []): ProjectDocument
    {
        Gate::authorize('update', $project);

        $validated = validator($data + ['file' => $file], $this->fileRules($project))->validate();

        $case = isset($validated['administrative_case_id']) ? AdministrativeCase::find($validated['administrative_case_id']) : null;
        $item = isset($validated['administrative_case_item_id']) ? AdministrativeCaseItem::find($validated['administrative_case_item_id']) : null;
        $this->assertItemBelongsToProject($project, $item);

        return DB::transaction(function () use ($project, $user, $validated, $file) {
            $path = $file->store("project-documents/{$project->id}", 'local');
            $document = $project->documents()->create([
                'administrative_case_id' => $validated['administrative_case_id'] ?? null,
                'administrative_case_item_id' => $validated['administrative_case_item_id'] ?? null,
                'uploaded_by' => $user->id,
                'name' => ($validated['name'] ?? null) ?: $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'description' => $validated['description'] ?? null,
                'document_type' => $validated['document_type'] ?? null,
                'version' => 1,
            ]);
            $this->syncItems($document, $validated['item_ids'] ?? []);
            app(ProjectActivityService::class)->record($project, 'document_uploaded', 'Dodan interni dokument: '.$document->name, $user, $document);
            $this->notifyWatchers($project, 'Novi dokument na projektu: '.$document->name);

            return $document;
        });
    }

    /** Način B — vanjski link na dokument u drugoj aplikaciji. */
    public function storeExternalLink(Project $project, User $user, array $data): DocumentReference
    {
        Gate::authorize('update', $project);

        $validated = validator($data, $this->referenceRules($project))->validate();
        $this->assertSafeUrl($validated['url']);

        return DB::transaction(function () use ($project, $validated) {
            $reference = $this->createReference($project, $validated, 'Vanjski link', $validated['url']);
            $this->syncItems($reference, $validated['item_ids'] ?? []);

            return $reference;
        });
    }

    /** Način C — priprema za interni protokol; sprema metapodatke bez kopiranja datoteke. */
    public function storeProtocolReference(Project $project, User $user, array $data): DocumentReference
    {
        Gate::authorize('update', $project);

        $rules = $this->referenceRules($project);
        unset($rules['url'], $rules['source_app'], $rules['item_ids'], $rules['item_ids.*']);
        $rules['external_document_id'] = ['required', 'string', 'max:255'];

        $validated = validator($data, $rules)->validate();

        return DB::transaction(fn () => $this->createReference($project, $validated, 'Interni protokol', $validated['external_document_id']));
    }

    /** Zamjena datoteke novom verzijom — prethodna verzija ostaje sačuvana. */
    public function replaceFileVersion(ProjectDocument $document, User $user, UploadedFile $file, ?string $name = null): ProjectDocument
    {
        Gate::authorize('update', $document->project);

        validator(['file' => $file], [
            'file' => ['required', 'file', 'mimes:'.config('projectflow.documents.allowed_mimes'), 'max:'.config('projectflow.documents.max_size')],
        ])->validate();

        return DB::transaction(function () use ($document, $user, $file, $name) {
            $path = $file->store("project-documents/{$document->project_id}", 'local');
            $newVersion = $document->project->documents()->create([
                'administrative_case_id' => $document->administrative_case_id,
                'administrative_case_item_id' => $document->administrative_case_item_id,
                'uploaded_by' => $user->id,
                'name' => $name ?: $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'description' => $document->description,
                'document_type' => $document->document_type,
                'version' => $document->version + 1,
            ]);
            $document->update(['replaced_by_id' => $newVersion->id, 'is_archived' => true]);
            $newVersion->linkedItems()->sync($document->linkedItems()->pluck('administrative_case_items.id'));
            app(ProjectActivityService::class)->record($document->project, 'document_replaced', 'Dokument zamijenjen novom verzijom: '.$newVersion->name, $user, $newVersion, 'Verzija '.$document->version.' → '.$newVersion->version);

            return $newVersion;
        });
    }

    /** Arhiviranje dokumenata — podaci i istorija ostaju sačuvani. */
    public function archiveDocument(ProjectDocument $document, User $user, bool $archive = true): void
    {
        Gate::authorize('update', $document->project);
        $document->update(['is_archived' => $archive]);
        app(ProjectActivityService::class)->record($document->project, $archive ? 'document_archived' : 'document_restored', ($archive ? 'Arhiviran dokument: ' : 'Dokument vraćen iz arhive: ').$document->name, $user, $document);
    }

    public function archiveReference(DocumentReference $reference, User $user, bool $archive = true): void
    {
        Gate::authorize('update', $reference->project);
        $reference->update(['is_archived' => $archive]);
        app(ProjectActivityService::class)->record($reference->project, $archive ? 'document_archived' : 'document_restored', ($archive ? 'Arhiviran dokument: ' : 'Dokument vraćen iz arhive: ').$reference->title, $user, $reference);
    }

    /** Uređivanje metapodataka reference (vanjski link / protokol). */
    public function updateReference(DocumentReference $reference, User $user, array $data): DocumentReference
    {
        Gate::authorize('update', $reference->project);

        $rules = $this->referenceRules($reference->project);
        unset($rules['administrative_case_id'], $rules['administrative_case_item_id'], $rules['item_ids'], $rules['item_ids.*']);
        $rules['url'] = ['nullable', 'string', 'max:2048', 'url'];

        $validated = validator($data, $rules)->validate();
        if (($validated['url'] ?? null) !== null) {
            $this->assertSafeUrl($validated['url']);
        }
        $reference->update($validated);
        app(ProjectActivityService::class)->record($reference->project, 'document_updated', 'Ažurirani podaci dokumenta: '.$reference->title, $user, $reference);

        return $reference->refresh();
    }

    /** Povezivanje jednog dokumenta s više stavki. */
    public function syncLinkedItems(ProjectDocument|DocumentReference $document, array $itemIds, User $user): void
    {
        Gate::authorize('update', $document->project);
        validator(['item_ids' => $itemIds], ['item_ids' => ['array'], 'item_ids.*' => [$this->projectItemRule($document->project)]])->validate();
        $document->linkedItems()->sync($itemIds);
        app(ProjectActivityService::class)->record($document->project, 'document_linked', 'Povezane stavke dokumenta: '.($document->name ?? $document->title), $user, $document);
    }

    public function deleteFile(ProjectDocument $document, User $user): void
    {
        Gate::authorize('update', $document->project);
        DB::transaction(function () use ($document, $user) {
            if ($document->previousVersions()->exists()) {
                throw ValidationException::withMessages(['document' => 'Dokument ima zamijenjene verzije; umjesto brisanja koristite arhiviranje.']);
            }
            Storage::disk('local')->delete($document->path);
            app(ProjectActivityService::class)->record($document->project, 'document_deleted', 'Obrisan dokument: '.$document->name, $user, $document);
            $document->delete();
        });
    }

    /** Dozvoljeni su samo http/https linkovi. */
    private function assertSafeUrl(string $url): void
    {
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['url' => 'Dozvoljeni su samo http i https linkovi.']);
        }
    }

    private function assertItemBelongsToProject(Project $project, ?AdministrativeCaseItem $item): void
    {
        if ($item !== null && $item->administrativeCase->project_id !== $project->id) {
            throw ValidationException::withMessages(['administrative_case_item_id' => 'Stavka ne pripada ovom projektu.']);
        }
    }

    private function createReference(Project $project, array $validated, string $system, string $externalId): DocumentReference
    {
        $case = isset($validated['administrative_case_id']) ? AdministrativeCase::find($validated['administrative_case_id']) : null;
        $item = isset($validated['administrative_case_item_id']) ? AdministrativeCaseItem::find($validated['administrative_case_item_id']) : null;
        $this->assertItemBelongsToProject($project, $item);

        return $project->documentReferences()->create([
            'administrative_case_id' => $case?->id,
            'administrative_case_item_id' => $item?->id,
            'external_system' => $system,
            'external_document_id' => $externalId,
            'title' => $validated['title'],
            'url' => $validated['url'] ?? null,
            'source_app' => $validated['source_app'] ?? null,
            'document_type' => $validated['document_type'] ?? null,
            'protocol_number' => $validated['protocol_number'] ?? null,
            'document_date' => $validated['document_date'] ?? null,
            'description' => $validated['description'] ?? null,
            'direction' => $validated['direction'],
            'sync_status' => DocumentSyncStatus::Pending,
            'version' => 1,
        ]);
    }

    private function syncItems(ProjectDocument|DocumentReference $document, array $itemIds): void
    {
        if ($itemIds !== []) {
            $document->linkedItems()->sync($itemIds);
        }
    }

    private function notifyWatchers(Project $project, string $title): void
    {
        app(NotificationDispatchService::class)->dispatch(
            array_filter([$project->responsible, ...$project->members]),
            NotificationKind::DocumentLinked,
            $title,
            route('projects.show', $project),
        );
    }

    /** Stavka mora pripadati jednom od postupaka ovog projekta. */
    private function projectItemRule(Project $project): Exists
    {
        return Rule::exists('administrative_case_items', 'id')
            ->whereIn('administrative_case_id', $project->administrativeCases()->pluck('id')->all());
    }

    private function fileRules(Project $project): array
    {
        return [
            'file' => ['required', 'file', 'mimes:'.config('projectflow.documents.allowed_mimes'), 'max:'.config('projectflow.documents.max_size')],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'administrative_case_id' => ['nullable', Rule::exists('administrative_cases', 'id')->where('project_id', $project->id)],
            'administrative_case_item_id' => ['nullable', Rule::exists('administrative_case_items', 'id')],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => [$this->projectItemRule($project)],
        ];
    }

    private function referenceRules(Project $project): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048', 'url'],
            'source_app' => ['nullable', 'string', 'max:100'],
            'document_type' => ['nullable', 'string', 'max:100'],
            'protocol_number' => ['nullable', 'string', 'max:100'],
            'document_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'direction' => ['required', Rule::enum(DocumentDirection::class)],
            'administrative_case_id' => ['nullable', Rule::exists('administrative_cases', 'id')->where('project_id', $project->id)],
            'administrative_case_item_id' => ['nullable', Rule::exists('administrative_case_items', 'id')],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => [$this->projectItemRule($project)],
        ];
    }
}
