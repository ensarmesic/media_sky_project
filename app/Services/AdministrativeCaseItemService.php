<?php

namespace App\Services;

use App\Enums\CaseItemPriority;
use App\Enums\CaseItemStatus;
use App\Enums\NotificationKind;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\ChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Stavke postupka (u kodu: stavke administrativnog predmeta). */
class AdministrativeCaseItemService
{
    /** Polja čije se promjene bilježe u istoriju (staro → novo). */
    private const TRACKED = ['name', 'description', 'status', 'priority', 'due_date', 'responsible_user_id', 'institution_id', 'recipient', 'submitted_at', 'response_at'];

    /** @param  array<string, mixed>  $data */
    public function create(AdministrativeCase $case, array $data, User $user): AdministrativeCaseItem
    {
        return DB::transaction(function () use ($case, $data, $user) {
            $item = $case->items()->create([
                'type' => 'other',
                'status' => CaseItemStatus::NotStarted,
                'priority' => CaseItemPriority::Medium,
                'progress_weight' => 1,
                ...$this->normalize($data),
                'created_by' => $user->id,
                'position' => $data['position'] ?? (((int) $case->items()->max('position')) + 1),
            ]);
            $this->log($item, $user, 'task.created', 'Kreirana stavka: '.$item->name);
            $case->project->ensureMember($item->responsible);
            $this->notifyAssignee($item);
            $this->syncCaseCompletion($case, $user);

            return $item;
        });
    }

    /** Izmjena bilo kojeg polja zadatka; svaka promjena praćenog polja se loguje. */
    /** @param  array<string, mixed>  $data */
    public function update(AdministrativeCaseItem $item, array $data, User $user): AdministrativeCaseItem
    {
        $data = $this->normalize($data);

        return DB::transaction(function () use ($item, $data, $user) {
            $before = collect(self::TRACKED)->mapWithKeys(fn (string $field) => [$field => $this->plain($item->getAttribute($field))])->all();
            $previousStatus = $item->status;

            $item->fill($data);
            if ($item->isDirty('status') && $item->status === CaseItemStatus::WaitingResponse && $previousStatus !== CaseItemStatus::WaitingResponse) {
                // Periodični podsjetnik računa interval od trenutka kada je zadatak počeo čekati.
                $item->response_reminded_at = now();
                $item->response_reminder_interval ??= (int) config('projectflow.reminders.waiting_default_interval', 14);
            }
            if ($item->isDirty('due_date')) {
                $item->deadline_reminded_at = null;
                $item->overdue_reminded_at = null;
            }
            $item->save();

            foreach (self::TRACKED as $field) {
                $after = $this->plain($item->getAttribute($field));
                if ($before[$field] !== $after) {
                    $this->logFieldChange($item, $user, $field, $before[$field], $after);
                }
            }

            if ($before['responsible_user_id'] !== $this->plain($item->responsible_user_id)) {
                $item->administrativeCase->project->ensureMember($item->load('responsible')->responsible);
                $this->notifyAssignee($item);
            }
            if ($before['status'] !== $item->status->value) {
                $this->notifyStatusChange($item, $previousStatus);
            }
            $this->syncCaseCompletion($item->administrativeCase, $user);

            return $item->refresh();
        });
    }

    public function changeStatus(AdministrativeCaseItem $item, CaseItemStatus $status, User $user): AdministrativeCaseItem
    {
        return $item->status === $status ? $item : $this->update($item, ['status' => $status], $user);
    }

    /** Kanban: premještanje zadatka u kolonu (status) i na poziciju unutar nje. */
    public function moveOnBoard(AdministrativeCaseItem $item, CaseItemStatus $status, int $position, User $user): void
    {
        DB::transaction(function () use ($item, $status, $position, $user) {
            if ($item->status !== $status) {
                $this->update($item, ['status' => $status], $user);
            }

            $projectId = $item->administrativeCase->project_id;
            $column = AdministrativeCaseItem::query()
                ->where('is_active', true)
                ->where('status', $status->value)
                ->whereKeyNot($item->id)
                ->whereHas('administrativeCase', fn ($query) => $query->where('project_id', $projectId)->where('is_archived', false))
                ->orderBy('position')->orderBy('id')
                ->pluck('id')
                ->all();
            array_splice($column, max(0, min($position, count($column))), 0, [$item->id]);
            foreach ($column as $index => $id) {
                AdministrativeCaseItem::whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }

    /** Drag & drop: stavka se premješta na zadatu poziciju (0 = prva) unutar svog postupka. */
    public function moveTo(AdministrativeCaseItem $item, int $position): void
    {
        DB::transaction(function () use ($item, $position) {
            $ids = $item->administrativeCase->items()->where('is_active', true)->whereKeyNot($item->id)->pluck('id')->all();
            array_splice($ids, max(0, min($position, count($ids))), 0, [$item->id]);
            foreach ($ids as $index => $id) {
                AdministrativeCaseItem::whereKey($id)->update(['position' => $index + 1]);
            }
        });
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

    /** Kopija stavke (sa checklistom) u istom postupku. */
    public function duplicate(AdministrativeCaseItem $item, User $user): AdministrativeCaseItem
    {
        return DB::transaction(function () use ($item, $user) {
            $copy = $this->create($item->administrativeCase, [
                ...$item->only(['name', 'description', 'priority', 'due_date', 'responsible_user_id', 'institution_id', 'notes', 'type', 'progress_weight', 'is_required_for_closure']),
                'name' => $item->name.' (kopija)',
            ], $user);
            foreach ($item->checklistItems as $checklistItem) {
                $copy->checklistItems()->create($checklistItem->only(['title', 'position']));
            }

            return $copy;
        });
    }

    public function addChecklistItem(AdministrativeCaseItem $item, string $title): ChecklistItem
    {
        return $item->checklistItems()->create([
            'title' => trim($title),
            'position' => ((int) $item->checklistItems()->max('position')) + 1,
        ]);
    }

    /** Nova pozicija stavke checkliste (drag & drop). */
    public function moveChecklistItem(ChecklistItem $checklistItem, int $position): void
    {
        DB::transaction(function () use ($checklistItem, $position) {
            $ids = $checklistItem->task->checklistItems()->whereKeyNot($checklistItem->id)->pluck('id')->all();
            array_splice($ids, max(0, min($position, count($ids))), 0, [$checklistItem->id]);
            foreach ($ids as $index => $id) {
                ChecklistItem::whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }

    /** "Brisanje" stavke: stavka se deaktivira, a istorija i dokumenti ostaju sačuvani. */
    public function deactivate(AdministrativeCaseItem $item, User $user): void
    {
        $item->update(['is_active' => false]);
        $this->log($item, $user, 'task.deleted', 'Obrisana stavka: '.$item->name);
        $this->syncCaseCompletion($item->administrativeCase, $user);
    }

    /** Upis u centralni log aktivnosti projekta (subjekt je zadatak). */
    /** @param  array<string, mixed>  $metadata */
    public function log(AdministrativeCaseItem $item, User $user, string $event, string $title, array $metadata = []): void
    {
        app(ProjectActivityService::class)->record($item->administrativeCase->project, $event, $title, $user, $item, null, $metadata);
    }

    private function logFieldChange(AdministrativeCaseItem $item, User $user, string $field, mixed $old, mixed $new): void
    {
        $this->log($item, $user, 'task.'.$field, 'Izmijenjena stavka: '.$item->name, ['field' => $field, 'old' => $old, 'new' => $new]);
    }

    private function notifyAssignee(AdministrativeCaseItem $item): void
    {
        if ($item->responsible_user_id === null) {
            return;
        }

        app(NotificationDispatchService::class)->dispatch(
            [$item->responsible],
            NotificationKind::TaskAssigned,
            'Dodijeljena vam je stavka: '.$item->name,
            $item->url(),
            $item->due_date?->format('Y-m-d'),
        );
    }

    private function notifyStatusChange(AdministrativeCaseItem $item, CaseItemStatus $previous): void
    {
        app(NotificationDispatchService::class)->dispatch(
            [$item->responsible],
            NotificationKind::StatusChanged,
            $item->name.': '.$previous->label().' → '.$item->status->label(),
            $item->url(),
            $item->due_date?->format('Y-m-d'),
        );
    }

    /** Vrijednost za log: enum → value, datum → Y-m-d, broj → string. */
    private function plain(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach (['description', 'responsible_user_id', 'institution_id', 'recipient', 'start_date', 'submitted_at', 'response_at', 'due_date', 'outcome', 'notes', 'response_reminder_interval'] as $field) {
            if (($data[$field] ?? null) === '') {
                $data[$field] = null;
            }
        }

        return $data;
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
        $case->activities()->create(['user_id' => $user->id, 'event_type' => $isComplete ? 'completed' : 'reopened', 'title' => $isComplete ? 'Postupak završen' : 'Postupak ponovo otvoren', 'description' => $oldName.' → '.$target->name]);
    }
}
