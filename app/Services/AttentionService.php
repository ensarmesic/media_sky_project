<?php

namespace App\Services;

use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCaseItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Zahtijeva pažnju": stavke koje kasne, čekaju odgovor duže od N dana (config
 * projectflow.attention.waiting_days) ili imaju rok u narednih M dana
 * (projectflow.attention.due_within_days). Redoslijed: kasni → čeka predugo → rok uskoro.
 */
class AttentionService
{
    public const OVERDUE = 'overdue';

    public const WAITING = 'waiting';

    public const DUE_SOON = 'due_soon';

    /**
     * Stavke koje traže pažnju, grupisane po projektu (jedan upit za sve projekte).
     *
     * @param  iterable<int>  $projectIds
     * @return Collection<int, Collection<int, array{item: AdministrativeCaseItem, type: string, reason: string, date: ?CarbonInterface, rank: int}>>
     */
    public function forProjects(iterable $projectIds, ?int $limitPerProject = null): Collection
    {
        $waitingDays = (int) config('projectflow.attention.waiting_days', 10);
        $dueWithin = (int) config('projectflow.attention.due_within_days', 5);

        $items = AdministrativeCaseItem::query()
            ->open()
            ->with(['administrativeCase:id,project_id,name,is_archived', 'responsible:id,name'])
            ->whereHas('administrativeCase', fn (Builder $query) => $query->whereIn('project_id', collect($projectIds)->all())->where('is_archived', false))
            ->where(fn (Builder $query) => $query
                ->whereDate('due_date', '<=', today()->addDays($dueWithin))
                ->orWhere(fn (Builder $waiting) => $waiting
                    ->where('status', CaseItemStatus::WaitingResponse->value)
                    ->whereDate('submitted_at', '<=', today()->subDays($waitingDays))))
            ->get();

        return $items
            ->map(fn (AdministrativeCaseItem $item) => $this->describe($item, $waitingDays, $dueWithin))
            ->filter()
            ->sortBy(fn (array $entry) => [$entry['rank'], $entry['date']->timestamp ?? PHP_INT_MAX])
            ->groupBy(fn (array $entry) => $entry['item']->administrativeCase->project_id)
            ->map(fn (Collection $entries) => $limitPerProject === null ? $entries->values() : $entries->values()->take($limitPerProject));
    }

    /** @return Collection<int, array{item: AdministrativeCaseItem, type: string, reason: string, date: ?CarbonInterface, rank: int}> */
    public function forProject(int $projectId, ?int $limit = null): Collection
    {
        return $this->forProjects([$projectId], $limit)->get($projectId, collect());
    }

    /** @return array{item: AdministrativeCaseItem, type: string, reason: string, date: ?CarbonInterface, rank: int}|null */
    private function describe(AdministrativeCaseItem $item, int $waitingDays, int $dueWithin): ?array
    {
        $days = $item->daysUntilDue();
        $waiting = $item->daysWaiting();

        if ($item->isOverdue()) {
            $late = abs($days);
            $reason = $item->status === CaseItemStatus::WaitingResponse && $waiting !== null
                ? "Čeka se odgovor ({$waiting} ".self::days($waiting).'), rok istekao'
                : $item->status->label().', kasni '.$late.' '.self::days($late);

            return ['item' => $item, 'type' => self::OVERDUE, 'reason' => $reason, 'date' => $item->due_date, 'rank' => 0];
        }
        if ($waiting !== null && $waiting >= $waitingDays) {
            return ['item' => $item, 'type' => self::WAITING, 'reason' => "Čeka se odgovor ({$waiting} ".self::days($waiting).')', 'date' => $item->submitted_at, 'rank' => 1];
        }
        if ($days !== null && $days >= 0 && $days <= $dueWithin) {
            $when = match ($days) {
                0 => 'rok je danas',
                1 => 'rok je sutra',
                default => "rok za {$days} dana",
            };

            return ['item' => $item, 'type' => self::DUE_SOON, 'reason' => $item->status->label().', '.$when, 'date' => $item->due_date, 'rank' => 2];
        }

        return null;
    }

    /** "1 dan", "2 dana", "5 dana", "21 dan" (bosanska množina). */
    public static function days(int $count): string
    {
        return $count % 10 === 1 && $count % 100 !== 11 ? 'dan' : 'dana';
    }
}
