<?php

namespace App\Services;

use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCaseItem;
use App\Models\Institution;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Pregled po instituciji: otvorene stavke iz projekata koje korisnik smije vidjeti.
 * Grupe na detalju su isključive (stavka je samo u jednoj): čeka odgovor → potrebna dopuna → kasni → za predaju.
 */
class InstitutionOverview
{
    public const GROUPS = [
        'waiting' => 'Čeka odgovor',
        'overdue' => 'Kasni',
        'to_submit' => 'Za predaju',
        'supplement' => 'Potrebna dopuna',
    ];

    /** @return Builder<AdministrativeCaseItem> */
    public function openItems(User $user): Builder
    {
        return AdministrativeCaseItem::query()
            ->open()
            ->whereNotNull('institution_id')
            ->whereHas('administrativeCase', fn (Builder $case) => $case
                ->where('is_archived', false)
                ->whereIn('project_id', Project::visibleTo($user)->select('id')));
    }

    /**
     * Lista institucija sa brojačima, sortirano po onome što traži pažnju.
     *
     * @return Collection<int, array{institution: Institution, open: int<0, max>, waiting: int<0, max>, overdue: int<0, max>, longest: ?int}>
     */
    public function summaries(User $user, string $search = ''): Collection
    {
        $items = $this->openItems($user)->get(['id', 'institution_id', 'status', 'due_date', 'submitted_at', 'is_active'])->groupBy('institution_id');

        return Institution::query()
            ->when(trim($search) !== '', fn (Builder $query) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->orderBy('name')
            ->get()
            ->map(function (Institution $institution) use ($items) {
                /** @var EloquentCollection<int, AdministrativeCaseItem> $own */
                $own = $items->get($institution->id, new EloquentCollection);
                $waiting = $own->filter(fn (AdministrativeCaseItem $item) => $item->status === CaseItemStatus::WaitingResponse);

                return [
                    'institution' => $institution,
                    'open' => $own->count(),
                    'waiting' => $waiting->count(),
                    'overdue' => $own->filter(fn (AdministrativeCaseItem $item) => $item->isOverdue())->count(),
                    'longest' => $waiting->map(fn (AdministrativeCaseItem $item) => $item->daysWaiting())->filter(fn (?int $days) => $days !== null)->max(),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $b['overdue'] <=> $a['overdue'],
                fn (array $a, array $b) => ($b['longest'] ?? -1) <=> ($a['longest'] ?? -1),
                fn (array $a, array $b) => $b['waiting'] <=> $a['waiting'],
                fn (array $a, array $b) => $b['open'] <=> $a['open'],
            ])
            ->values();
    }

    /**
     * Otvorene stavke institucije grupisane za detalj (sa svim podacima za tabelu, bez N+1).
     *
     * @return array<string, EloquentCollection<int, AdministrativeCaseItem>>
     */
    public function groups(Institution $institution, User $user): array
    {
        $items = $this->openItems($user)
            ->where('institution_id', $institution->id)
            ->with(['administrativeCase:id,project_id,name', 'administrativeCase.project:id,name,code', 'responsible:id,name', 'institution:id,name'])
            ->withCount('documents')
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id')
            ->get();

        $groups = array_map(fn () => new EloquentCollection, self::GROUPS);
        foreach ($items as $item) {
            $groups[match (true) {
                in_array($item->status, [CaseItemStatus::WaitingResponse, CaseItemStatus::Submitted], true) => 'waiting',
                $item->status === CaseItemStatus::SupplementRequired => 'supplement',
                $item->isOverdue() => 'overdue',
                default => 'to_submit',
            }]->push($item);
        }
        $groups['waiting'] = $groups['waiting']->sortByDesc(fn (AdministrativeCaseItem $item) => $item->daysWaiting() ?? -1)->values();

        return $groups;
    }

    /**
     * Tekstualni spisak stavki koje čekaju odgovor (za e-mail ili poziv): projekat, stavka, datum predaje, broj protokola.
     *
     * @param  EloquentCollection<int, AdministrativeCaseItem>  $waiting
     */
    public function waitingListText(Institution $institution, EloquentCollection $waiting): string
    {
        $protocols = ProjectDocument::query()
            ->where('documentable_type', (new AdministrativeCaseItem)->getMorphClass())
            ->whereIn('documentable_id', $waiting->modelKeys())
            ->where('direction', 'outgoing')
            ->whereNotNull('protocol_number')
            ->whereNull('replaced_by_id')
            ->orderByDesc('document_date')->orderByDesc('id')
            ->get(['documentable_id', 'protocol_number'])
            ->unique('documentable_id')
            ->pluck('protocol_number', 'documentable_id');

        $lines = $waiting->map(fn (AdministrativeCaseItem $item) => '- '.$item->administrativeCase->project->name.' – '.$item->name
            .' (predato '.($item->submitted_at?->format('d.m.Y.') ?? '–')
            .($protocols->has($item->id) ? ', broj protokola '.$protocols[$item->id] : '').')');

        return 'Stavke koje čekaju odgovor – '.$institution->name.' (stanje '.local_time(now())->format('d.m.Y.').")\n".$lines->implode("\n");
    }
}
