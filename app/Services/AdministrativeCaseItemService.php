<?php

namespace App\Services;

use App\Enums\CaseItemStatus;
use App\Enums\NotificationKind;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Zadaci predmeta (tehnički: stavke administrativnog predmeta). */
class AdministrativeCaseItemService
{
    public function create(AdministrativeCase $case, array $data, User $user): AdministrativeCaseItem
    {
        return DB::transaction(function () use ($case, $data, $user) {
            $item = $case->items()->create([
                'type' => 'other',
                'status' => CaseItemStatus::NotStarted,
                'progress_weight' => 1,
                ...$this->normalize($data),
                'created_by' => $user->id,
                'position' => $data['position'] ?? (($case->items()->max('position') ?? 0) + 1),
            ]);
            $this->activity($item, $user, 'created', 'Zadatak kreiran');
            app(ProjectActivityService::class)->record($case->project, 'case_item_created', 'Novi zadatak: '.$item->name, $user, $item, 'Predmet: '.$case->name);
            $this->notifyAssignee($item, $user);

            return $item;
        });
    }

    /** Izmjena zadatka iz panela s detaljima. */
    public function update(AdministrativeCaseItem $item, array $data, User $user): AdministrativeCaseItem
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($item, $data, $user) {
            if (isset($data['status'])) {
                $status = $data['status'] instanceof CaseItemStatus ? $data['status'] : CaseItemStatus::from($data['status']);
                unset($data['status']);
                if ($status !== $item->status) {
                    $this->changeStatus($item, $status, $user);
                }
            }

            $oldDueDate = $item->due_date?->format('d.m.Y.');
            $oldResponsible = $item->responsible_user_id;
            $item->update($data);

            if ($item->wasChanged('due_date')) {
                $item->update(['deadline_reminded_at' => null, 'overdue_reminded_at' => null]);
                $this->activity($item, $user, 'due_date_changed', 'Rok promijenjen', ($oldDueDate ?? 'bez roka').' → '.($item->due_date?->format('d.m.Y.') ?? 'bez roka'));
            }
            if ($item->wasChanged('responsible_user_id')) {
                $item->load('responsible');
                $this->activity($item, $user, 'responsible_changed', 'Odgovorna osoba: '.($item->responsible?->name ?? 'nije dodijeljeno'));
                if ($item->responsible_user_id !== null && (int) $oldResponsible !== (int) $item->responsible_user_id) {
                    $this->notifyAssignee($item, $user);
                }
            }
            if (collect($item->getChanges())->except(['due_date', 'responsible_user_id', 'updated_at', 'deadline_reminded_at', 'overdue_reminded_at'])->isNotEmpty()) {
                $this->activity($item, $user, 'updated', 'Podaci zadatka ažurirani');
            }
            $this->syncCaseCompletion($item->administrativeCase, $user);

            return $item->refresh();
        });
    }

    public function changeStatus(AdministrativeCaseItem $item, CaseItemStatus $status, User $user): AdministrativeCaseItem
    {
        $old = $item->status;
        if ($old === $status) {
            return $item;
        }

        $updates = ['status' => $status, 'completed_at' => $status === CaseItemStatus::Completed ? ($item->completed_at ?? now()) : null];
        if ($status === CaseItemStatus::Submitted && $item->submitted_at === null) {
            $updates['submitted_at'] = now()->toDateString();
        }
        if ($status === CaseItemStatus::WaitingResponse && $item->response_reminder_interval === null) {
            $updates['response_reminder_interval'] = (int) config('projectflow.reminders.waiting_default_interval', 14);
        }
        // Periodični podsjetnik računa interval od trenutka kada je zadatak počeo čekati odgovor.
        $updates['response_reminded_at'] = $status === CaseItemStatus::WaitingResponse ? now() : null;
        $item->update($updates);

        $event = $status === CaseItemStatus::Completed ? 'completed' : ($old === CaseItemStatus::Completed ? 'reopened' : 'status_changed');
        $this->activity($item, $user, $event, 'Status: '.$status->label(), $old->label().' → '.$status->label());
        app(ProjectActivityService::class)->record($item->administrativeCase->project, 'case_item_status_changed', $item->name.': '.$status->label(), $user, $item, 'Predmet: '.$item->administrativeCase->name);

        if (in_array($status, [CaseItemStatus::WaitingResponse, CaseItemStatus::SupplementRequired], true)) {
            app(NotificationDispatchService::class)->dispatch(
                $this->recipients($item, except: $user),
                $status === CaseItemStatus::SupplementRequired ? NotificationKind::SupplementRequired : NotificationKind::StatusChanged,
                ($status === CaseItemStatus::SupplementRequired ? 'Potrebna dopuna: ' : 'Zadatak čeka odgovor: ').$item->name,
                $item->url(),
                $item->due_date?->format('Y-m-d'),
            );
        }
        $this->syncCaseCompletion($item->administrativeCase, $user);

        return $item->refresh();
    }

    /** Pomjeranje zadatka gore/dolje unutar predmeta (uz normalizaciju dupliranih pozicija). */
    public function movePosition(AdministrativeCaseItem $item, string $direction): void
    {
        DB::transaction(function () use ($item, $direction) {
            $ordered = $item->administrativeCase->items()->where('is_active', true)->orderBy('id')->get()->values();
            $index = $ordered->search(fn (AdministrativeCaseItem $other) => $other->is($item));
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || ! $ordered->has($target)) {
                return;
            }
            $ids = $ordered->pluck('id')->all();
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            foreach ($ids as $position => $id) {
                AdministrativeCaseItem::whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }

    public function deactivate(AdministrativeCaseItem $item, User $user): void
    {
        $item->update(['is_active' => false]);
        $this->activity($item, $user, 'deactivated', 'Zadatak uklonjen');
        app(ProjectActivityService::class)->record($item->administrativeCase->project, 'case_item_removed', 'Uklonjen zadatak: '.$item->name, $user, $item, 'Predmet: '.$item->administrativeCase->name);
        $this->syncCaseCompletion($item->administrativeCase, $user);
    }

    /** Bilješka u istoriju zadatka bez mijenjanja podataka. */
    public function addNote(AdministrativeCaseItem $item, User $user, string $note): void
    {
        $this->activity($item, $user, 'note', 'Napomena', $note);
        app(ProjectActivityService::class)->record($item->administrativeCase->project, 'case_item_note', 'Napomena na zadatak: '.$item->name, $user, $item, $note);
    }

    private function notifyAssignee(AdministrativeCaseItem $item, User $actor): void
    {
        if ($item->responsible_user_id === null || (int) $item->responsible_user_id === $actor->id) {
            return;
        }

        app(NotificationDispatchService::class)->dispatch(
            [$item->responsible],
            NotificationKind::TaskAssigned,
            'Dodijeljen vam je zadatak: '.$item->name,
            $item->url(),
            $item->due_date?->format('Y-m-d'),
        );
    }

    private function normalize(array $data): array
    {
        foreach (['description', 'responsible_user_id', 'institution_id', 'recipient', 'start_date', 'submitted_at', 'response_at', 'due_date', 'outcome', 'notes', 'response_reminder_interval'] as $field) {
            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * Odgovorna osoba zadatka i odgovorna osoba predmeta, bez duplikata i bez osobe koja je napravila izmjenu.
     *
     * @return array<int, User>
     */
    private function recipients(AdministrativeCaseItem $item, ?User $except = null): array
    {
        return collect([$item->responsible, $item->administrativeCase->responsible])
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $except !== null && $user->is($except))
            ->values()
            ->all();
    }

    private function activity(AdministrativeCaseItem $item, User $user, string $event, string $title, ?string $description = null): void
    {
        $item->activities()->create(['user_id' => $user->id, 'event_type' => $event, 'title' => $title, 'description' => $description]);
    }

    /** Predmet se automatski zatvara kada su svi zadaci završeni, i ponovo otvara ako se neki vrati. */
    private function syncCaseCompletion(AdministrativeCase $case, User $user): void
    {
        $case->load('items', 'status');
        $isComplete = $case->items->where('is_active', true)->isNotEmpty() && $case->progressPercentage() === 100;
        $targetKey = $isComplete ? 'resolved' : ($case->status?->system_key === 'resolved' ? 'in_preparation' : null);
        if (! $targetKey) {
            return;
        }
        $target = CaseStatus::where('system_key', $targetKey)->first();
        if (! $target || $case->case_status_id === $target->id) {
            return;
        }
        $oldName = $case->status?->name;
        $case->update(['case_status_id' => $target->id, 'closed_at' => $isComplete ? now()->toDateString() : null]);
        $case->activities()->create(['user_id' => $user->id, 'event_type' => $isComplete ? 'completed' : 'reopened', 'title' => $isComplete ? 'Predmet završen' : 'Predmet ponovo otvoren', 'description' => $oldName.' → '.$target->name]);
    }
}
