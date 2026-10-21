<?php

namespace App\Services;

use App\Enums\NotificationKind;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Dokumenti na projektu, postupku, stavci i komentaru (privatni disk, verzije). */
class DocumentService
{
    /**
     * Pravila za upload fajla (tip po ekstenziji + konfigurabilan limit veličine).
     *
     * @return list<string>
     */
    public static function fileRules(): array
    {
        return ['required', 'file', 'extensions:'.config('projectflow.documents.allowed_extensions'), 'max:'.config('projectflow.documents.max_size')];
    }

    /**
     * Poruke grešaka za upload na bosanskom.
     *
     * @return array<string, string>
     */
    public static function fileMessages(string $attribute = 'file'): array
    {
        $maxMb = (int) round(config('projectflow.documents.max_size') / 1024);

        return [
            "{$attribute}.max" => "Fajl je prevelik. Najveća dozvoljena veličina je {$maxMb} MB.",
            "{$attribute}.extensions" => 'Ova vrsta fajla nije dozvoljena. Dozvoljeno: '.config('projectflow.documents.allowed_labels').'.',
            "{$attribute}.uploaded" => "Upload nije uspio. Provjerite veličinu fajla (najviše {$maxMb} MB).",
            "{$attribute}.required" => 'Odaberite fajl.',
        ];
    }

    /** Ko smije dodavati dokumente na zapis. */
    public static function canUpload(User $user, Model $owner): bool
    {
        return match (true) {
            $owner instanceof Project => $user->can('update', $owner),
            $owner instanceof AdministrativeCase => $user->can('update', $owner),
            $owner instanceof AdministrativeCaseItem => $user->can('update', $owner),
            $owner instanceof Comment => $owner->user_id === $user->id || $user->isAdministrator(),
            default => false,
        };
    }

    /**
     * @param  array{name?: ?string, direction?: ?string, protocol_number?: ?string, document_date?: ?string, description?: ?string}  $meta
     */
    public function store(Model $owner, User $user, UploadedFile $file, array $meta = []): ProjectDocument
    {
        abort_unless(self::canUpload($user, $owner), 403);
        $project = Project::owning($owner);
        abort_if($project === null, 404);

        validator(['file' => $file, ...$meta], [
            'file' => self::fileRules(),
            'name' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', Rule::in(array_keys(ProjectDocument::DIRECTIONS))],
            'protocol_number' => ['nullable', 'string', 'max:100'],
            'document_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], self::fileMessages())->validate();

        return DB::transaction(function () use ($owner, $user, $file, $meta, $project) {
            $document = ProjectDocument::create([
                'project_id' => $project->id,
                'documentable_type' => $owner->getMorphClass(),
                'documentable_id' => $owner->getKey(),
                'administrative_case_id' => match (true) {
                    $owner instanceof AdministrativeCase => $owner->id,
                    $owner instanceof AdministrativeCaseItem => $owner->administrative_case_id,
                    default => null,
                },
                'administrative_case_item_id' => $owner instanceof AdministrativeCaseItem ? $owner->id : null,
                'uploaded_by' => $user->id,
                'name' => filled($meta['name'] ?? null) ? trim($meta['name']) : $file->getClientOriginalName(),
                'original_name' => $file->getClientOriginalName(),
                'path' => $file->store("project-documents/{$project->id}", ProjectDocument::DISK),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'direction' => $meta['direction'] ?? null ?: null,
                'protocol_number' => filled($meta['protocol_number'] ?? null) ? trim($meta['protocol_number']) : null,
                'document_date' => $meta['document_date'] ?? null ?: null,
                'description' => $meta['description'] ?? null ?: null,
                'version' => 1,
            ]);

            $this->log($document, $user, 'document.uploaded', 'Dodan dokument: '.$document->name);
            $this->notifyItemResponsible($document, 'Novi dokument na vašoj stavci: '.$document->name);

            return $document;
        });
    }

    /** Nova verzija: stara ostaje sačuvana (replaced_by_id), nova dobija verziju + 1. */
    public function replaceVersion(ProjectDocument $document, User $user, UploadedFile $file): ProjectDocument
    {
        abort_unless($document->replaced_by_id === null, 422, 'Nova verzija se dodaje na najnoviju verziju dokumenta.');
        Gate::authorize('view', $document);
        abort_unless($document->documentable === null || self::canUpload($user, $document->documentable), 403);

        validator(['file' => $file], ['file' => self::fileRules()], self::fileMessages())->validate();

        return DB::transaction(function () use ($document, $user, $file) {
            $newVersion = $document->replicate(['replaced_by_id']);
            $newVersion->fill([
                'uploaded_by' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'path' => $file->store("project-documents/{$document->project_id}", ProjectDocument::DISK),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'version' => $document->version + 1,
                'is_archived' => false,
            ])->save();
            $document->update(['replaced_by_id' => $newVersion->id]);

            $this->log($newVersion, $user, 'document.version', 'Nova verzija dokumenta: '.$newVersion->name.' (verzija '.$newVersion->version.')');

            return $newVersion;
        });
    }

    /** Brisanje dokumenta i svih njegovih starijih verzija (zapisi i fajlovi). */
    public function delete(ProjectDocument $document, User $user): void
    {
        Gate::authorize('delete', $document);

        DB::transaction(function () use ($document, $user) {
            $versions = $document->versionHistory()->prepend($document);
            $this->log($document, $user, 'document.deleted', 'Obrisan dokument: '.$document->name);
            foreach ($versions as $version) {
                Storage::disk(ProjectDocument::DISK)->delete($version->path);
            }
            ProjectDocument::whereKey($versions->modelKeys())->update(['replaced_by_id' => null]);
            ProjectDocument::whereKey($versions->modelKeys())->delete();
        });
    }

    private function log(ProjectDocument $document, User $user, string $event, string $title): void
    {
        $subject = $document->documentable instanceof Comment
            ? $document->documentable->commentable
            : ($document->documentable ?? $document->project);

        app(ProjectActivityService::class)->record($document->project, $event, $title, $user, $subject, null, ['document_id' => $document->id, 'name' => $document->name]);
    }

    private function notifyItemResponsible(ProjectDocument $document, string $title): void
    {
        $owner = $document->documentable instanceof Comment ? $document->documentable->commentable : $document->documentable;
        if (! $owner instanceof AdministrativeCaseItem || $owner->responsible === null) {
            return;
        }

        app(NotificationDispatchService::class)->dispatch([$owner->responsible], NotificationKind::TaskCommented, $title, $owner->url());
    }

    /** Validacijska greška za neuspjelu operaciju nad dokumentom. */
    public static function fail(string $message, string $field = 'file'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
