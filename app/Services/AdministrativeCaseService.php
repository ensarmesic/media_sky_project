<?php

namespace App\Services;

use App\Enums\CaseItemStatus;
use App\Enums\NotificationKind;
use App\Models\AdministrativeCase;
use App\Models\CaseTemplate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdministrativeCaseService
{
    public function create(array $data, User $user): AdministrativeCase
    {
        $data = $this->normalizeNullableFields($data);

        return DB::transaction(function () use ($data, $user) {
            $data['position'] ??= ((int) AdministrativeCase::where('project_id', $data['project_id'] ?? null)->max('position')) + 1;
            $case = AdministrativeCase::create([...$data, 'created_by' => $user->id]);
            $case->activities()->create(['user_id' => $user->id, 'event_type' => 'created', 'title' => 'Predmet kreiran']);
            app(ProjectActivityService::class)->record($case->project, 'case_created', 'Kreiran predmet: '.$case->name, $user, $case);

            return $case;
        });
    }

    public function update(AdministrativeCase $case, array $data, User $user): AdministrativeCase
    {
        $data = $this->normalizeNullableFields($data);

        return DB::transaction(function () use ($case, $data, $user) {
            $oldStatus = $case->status;
            $oldDeadline = $case->response_due_at?->format('Y-m-d');
            $case->update($data);
            $statusChanged = $case->wasChanged('case_status_id');

            if ($oldDeadline !== $case->response_due_at?->format('Y-m-d')) {
                $case->update(['deadline_reminded_at' => null, 'overdue_reminded_at' => null]);
                $description = ($oldDeadline ?: 'bez roka').' → '.($case->response_due_at?->format('d.m.Y.') ?: 'bez roka');
                $case->activities()->create(['user_id' => $user->id, 'event_type' => 'deadline_changed', 'title' => 'Rok za odgovor promijenjen', 'description' => $description]);
                app(ProjectActivityService::class)->record($case->project, 'case_deadline_changed', 'Promijenjen rok predmeta: '.$case->name, $user, $case, $description);
            }

            if ($statusChanged) {
                $newStatus = $case->status()->first();
                if ($newStatus->system_key === 'resolved' && $case->items()->where('is_active', true)->where('is_required_for_closure', true)->whereNot('status', 'completed')->exists()) {
                    throw ValidationException::withMessages(['case_status_id' => 'Predmet se ne može zatvoriti dok obavezni završni koraci nisu završeni.']);
                }
                $description = $oldStatus->name.' → '.$newStatus->name;
                $case->activities()->create(['user_id' => $user->id, 'event_type' => 'status_changed', 'title' => 'Status promijenjen', 'description' => $description, 'metadata' => ['from' => $oldStatus->system_key, 'to' => $newStatus->system_key]]);
                $notificationTitle = match ($newStatus->system_key) {
                    'supplement_required' => 'Zatražena dopuna za predmet: '.$case->name,
                    'resolved' => 'Zaprimljen odgovor / predmet završen: '.$case->name,
                    default => 'Promijenjen status predmeta: '.$case->name,
                };
                app(NotificationDispatchService::class)->dispatch(
                    [$case->responsible],
                    $newStatus->system_key === 'supplement_required' ? NotificationKind::SupplementRequired : NotificationKind::StatusChanged,
                    $notificationTitle,
                    $case->url(),
                    $case->response_due_at?->format('Y-m-d'),
                );
                app(ProjectActivityService::class)->record($case->project, 'case_status_changed', 'Promijenjen status predmeta: '.$case->name, $user, $case, $description);
            }

            $case->activities()->create(['user_id' => $user->id, 'event_type' => 'updated', 'title' => 'Podaci predmeta ažurirani']);
            app(ProjectActivityService::class)->record($case->project, 'case_updated', 'Ažuriran predmet: '.$case->name, $user, $case);

            return $case->refresh();
        });
    }

    public function addComment(AdministrativeCase $case, User $user, string $comment): void
    {
        $case->activities()->create(['user_id' => $user->id, 'event_type' => 'comment', 'title' => 'Dodana bilješka', 'description' => $comment]);
        app(ProjectActivityService::class)->record($case->project, 'case_comment', 'Dodana bilješka na predmet: '.$case->name, $user, $case, $comment);
    }

    /** Arhiviranje postupka — podaci i istorija ostaju sačuvani, postupak izlazi iz aktivnih lista. */
    public function setArchived(AdministrativeCase $case, User $user, bool $archive = true): AdministrativeCase
    {
        $case->update(['is_archived' => $archive, 'archived_at' => $archive ? now() : null]);
        $case->activities()->create(['user_id' => $user->id, 'event_type' => $archive ? 'archived' : 'restored', 'title' => $archive ? 'Predmet arhiviran' : 'Predmet vraćen iz arhive']);
        app(ProjectActivityService::class)->record($case->project, $archive ? 'case_archived' : 'case_restored', ($archive ? 'Arhiviran predmet: ' : 'Predmet vraćen iz arhive: ').$case->name, $user, $case);

        return $case->refresh();
    }

    /** Promjena redoslijeda postupaka unutar projekta. */
    public function movePosition(AdministrativeCase $case, string $direction): void
    {
        DB::transaction(function () use ($case, $direction) {
            // Normalizacija: stariji zapisi mogu imati istu poziciju (npr. 0), pa zamjena ne bi imala efekta.
            $ordered = $case->project->administrativeCases()->where('is_archived', (bool) $case->is_archived)->get()->values();
            $index = $ordered->search(fn (AdministrativeCase $other) => $other->is($case));
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || ! $ordered->has($target)) {
                return;
            }
            $ids = $ordered->pluck('id')->all();
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            foreach ($ids as $position => $id) {
                AdministrativeCase::whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }

    /** Kopiranje postupka sa svim aktivnim stavkama. */
    public function duplicate(AdministrativeCase $case, User $user, ?string $newName = null): AdministrativeCase
    {
        return DB::transaction(function () use ($case, $user, $newName) {
            $copy = $case->replicate(['archived_at']);
            $copy->name = $newName ?: $case->name.' (kopija)';
            $copy->is_archived = false;
            $copy->closed_at = null;
            $copy->created_by = $user->id;
            $copy->position = (($case->project->administrativeCases()->max('position')) ?? 0) + 1;
            $copy->save();

            foreach ($case->items()->where('is_active', true)->orderBy('position')->get() as $item) {
                $itemCopy = $item->replicate();
                $itemCopy->administrative_case_id = $copy->id;
                $itemCopy->status = CaseItemStatus::New;
                $itemCopy->submitted_at = null;
                $itemCopy->response_at = null;
                $itemCopy->completed_at = null;
                $itemCopy->response_reminded_at = null;
                $itemCopy->created_by = $user->id;
                $itemCopy->save();
            }

            $copy->activities()->create(['user_id' => $user->id, 'event_type' => 'created', 'title' => 'Predmet kopiran iz: '.$case->name]);
            app(ProjectActivityService::class)->record($case->project, 'case_created', 'Kopiran predmet: '.$copy->name, $user, $copy);

            return $copy->refresh();
        });
    }

    /** Kopiranje aktivnih stavki iz drugog postupka istog projekta. */
    public function copyItemsFrom(AdministrativeCase $case, AdministrativeCase $source, User $user): int
    {
        abort_unless($source->project_id === $case->project_id, 422, 'Stavke se mogu kopirati samo unutar istog projekta.');

        return DB::transaction(function () use ($case, $source, $user) {
            $position = ($case->items()->max('position') ?? 0);
            $copied = 0;
            foreach ($source->items()->where('is_active', true)->orderBy('position')->get() as $item) {
                $itemCopy = $item->replicate();
                $itemCopy->administrative_case_id = $case->id;
                $itemCopy->status = CaseItemStatus::New;
                $itemCopy->submitted_at = null;
                $itemCopy->response_at = null;
                $itemCopy->completed_at = null;
                $itemCopy->response_reminded_at = null;
                $itemCopy->position = ++$position;
                $itemCopy->created_by = $user->id;
                $itemCopy->save();
                $copied++;
            }
            if ($copied > 0) {
                $case->activities()->create(['user_id' => $user->id, 'event_type' => 'items_copied', 'title' => 'Kopirani zadaci iz predmeta: '.$source->name, 'description' => 'Broj kopiranih zadataka: '.$copied]);
                app(ProjectActivityService::class)->record($case->project, 'case_items_copied', 'Kopirani zadaci ('.$copied.') iz predmeta: '.$source->name, $user, $case);
            }

            return $copied;
        });
    }

    /** Kreiranje postupka iz šablona (naziv, tip, institucija i stavke šablona). */
    public function createFromTemplate(Project $project, array $data, User $user): AdministrativeCase
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $template = CaseTemplate::with('items')->findOrFail($data['case_template_id']);

            // Prazne vrijednosti iz forme ('' ili null) preuzimaju vrijednost iz šablona.
            $caseTypeId = ($data['case_type_id'] ?? null) ?: $template->case_type_id;
            if ($caseTypeId === null) {
                throw ValidationException::withMessages(['case_type_id' => 'Šablon nema definisanu vrstu predmeta — odaberite vrstu.']);
            }

            $case = $this->create([
                ...collect($data)->except(['case_template_id'])->all(),
                'project_id' => $project->id,
                'case_type_id' => $caseTypeId,
                'institution_id' => ($data['institution_id'] ?? null) ?: $template->institution_id,
                'description' => ($data['description'] ?? null) ?: $template->description,
            ], $user);

            $case->activities()->create(['user_id' => $user->id, 'event_type' => 'created', 'title' => 'Predmet kreiran iz šablona: '.$template->name]);

            foreach ($template->items as $templateItem) {
                app(AdministrativeCaseItemService::class)->create($case, [
                    'name' => $templateItem->name,
                    'type' => $templateItem->type->value,
                    'progress_weight' => $templateItem->progress_weight,
                    'is_required_for_closure' => $templateItem->is_required_for_closure,
                    'notes' => $templateItem->notes,
                    'position' => $templateItem->position,
                ], $user);
            }

            return $case->refresh();
        });
    }

    private function normalizeNullableFields(array $data): array
    {
        foreach (['description', 'institution_id', 'submitted_at', 'response_due_at', 'outcome', 'notes', 'closed_at'] as $field) {
            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
