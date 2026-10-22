<?php

use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCaseItem;
use App\Services\AdministrativeCaseItemService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Moje stavke: otvorene stavke iz svih projekata gdje sam odgovorna osoba, grupisane po hitnosti. */
new #[Title('Moje stavke')] class extends Component {
    public const GROUPS = [
        'overdue' => 'Kasni',
        'waiting' => 'Čeka odgovor',
        'this_week' => 'Rok ove sedmice',
        'later' => 'Kasnije',
        'no_deadline' => 'Bez roka',
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Otvorene stavke grupisane: kasni → čeka odgovor → rok ove sedmice → kasnije → bez roka.
     *
     * @return Collection<string, Collection<int, AdministrativeCaseItem>>
     */
    #[Computed]
    public function groups(): Collection
    {
        $items = AdministrativeCaseItem::query()
            ->open()
            ->with(['administrativeCase:id,project_id,name', 'administrativeCase.project:id,name', 'institution:id,name'])
            ->where('responsible_user_id', auth()->id())
            ->whereHas('administrativeCase', fn (Builder $query) => $query->where('is_archived', false))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id')
            ->get();

        $endOfWeek = local_today()->endOfWeek();
        $grouped = $items->groupBy(fn (AdministrativeCaseItem $item) => match (true) {
            $item->isOverdue() => 'overdue',
            $item->status === CaseItemStatus::WaitingResponse => 'waiting',
            $item->due_date === null => 'no_deadline',
            $item->due_date->lte($endOfWeek) => 'this_week',
            default => 'later',
        });
        // Najduže čekanje prvo.
        if ($grouped->has('waiting')) {
            $grouped['waiting'] = $grouped['waiting']->sortByDesc(fn (AdministrativeCaseItem $item) => $item->daysWaiting() ?? -1)->values();
        }

        return collect(self::GROUPS)->keys()->filter(fn (string $key) => $grouped->has($key))->mapWithKeys(fn (string $key) => [$key => $grouped[$key]]);
    }

    public function changeItemStatus(int $itemId, string $status, AdministrativeCaseItemService $service): void
    {
        $item = AdministrativeCaseItem::with('administrativeCase.project')->findOrFail($itemId);
        Gate::authorize('update', $item);
        validator(['status' => $status], ['status' => ['required', Rule::enum(CaseItemStatus::class)]])->validate();
        $service->changeStatus($item, CaseItemStatus::from($status), auth()->user());
        unset($this->groups);

        if (in_array($status, CaseItemStatus::closedValues(), true)) {
            Flux::toast('Stavka „'.$item->name.'“ je '.mb_strtolower(CaseItemStatus::from($status)->label()).' i uklonjena je s liste.');
        }
    }

    #[On('task-updated')]
    public function refreshGroups(): void
    {
        unset($this->groups);
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">Moje stavke</flux:heading>
            <flux:subheading class="mt-1">Otvorene stavke iz svih projekata u kojima ste odgovorna osoba.</flux:subheading>
        </div>
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Pretraži stavke…" aria-label="Pretraži stavke" class="sm:max-w-64" clearable />
    </div>

    @if($this->groups->isNotEmpty())
        <nav class="flex flex-wrap gap-2" aria-label="Grupe">
            @foreach($this->groups as $key => $items)
                <a href="#grupa-{{ $key }}" @class(['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm', 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300' => $key === 'overdue', 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-300' => $key === 'waiting', 'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! in_array($key, ['overdue', 'waiting'], true)])>
                    {{ $this::GROUPS[$key] }} <span class="font-semibold tabular-nums">{{ $items->count() }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    @php($canEdit = auth()->user()->role !== App\Enums\UserRole::Viewer)
    <div class="flex flex-col gap-5" wire:loading.delay.class="opacity-60">
        @forelse($this->groups as $key => $items)
            <section id="grupa-{{ $key }}" class="scroll-mt-20 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" wire:key="group-{{ $key }}" aria-labelledby="naslov-{{ $key }}">
                <h2 id="naslov-{{ $key }}" class="flex items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 text-sm font-semibold dark:border-zinc-800 dark:bg-zinc-800/50">
                    <span @class(['size-2 rounded-full', 'bg-red-500' => $key === 'overdue', 'bg-orange-400' => $key === 'waiting', 'bg-blue-500' => $key === 'this_week', 'bg-zinc-400' => in_array($key, ['later', 'no_deadline'], true)])></span>
                    {{ $this::GROUPS[$key] }}
                    <span class="font-normal text-zinc-500">({{ $items->count() }})</span>
                </h2>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($items as $item)
                        @php($case = $item->administrativeCase)
                        @php($daysLeft = $item->daysUntilDue())
                        <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-4" wire:key="my-item-{{ $item->id }}">
                            <div class="min-w-0 flex-1">
                                <a href="{{ $item->url() }}" x-on:click="if (! $event.metaKey && ! $event.ctrlKey && window.Livewire?.getByName?.('task-drawer')?.length) { $event.preventDefault(); $dispatch('open-task', { id: {{ $item->id }} }) }" class="block truncate text-sm font-medium hover:text-blue-600">{{ $item->name }}</a>
                                <p class="truncate text-xs text-zinc-500">
                                    <a href="{{ route('projects.show', $case->project_id) }}" wire:navigate class="hover:text-blue-600">{{ $case->project->name }}</a>
                                    <span aria-hidden="true">›</span> {{ $case->name }}@if($recipient = $item->recipientName()) · {{ $recipient }}@endif
                                </p>
                            </div>
                            <div class="flex items-center gap-3 sm:shrink-0">
                                @if($key === 'waiting' && $item->daysWaiting() !== null)
                                    <span class="text-xs text-orange-600 dark:text-orange-400">čeka {{ $item->daysWaiting() }} {{ $item->daysWaiting() % 10 === 1 && $item->daysWaiting() % 100 !== 11 ? 'dan' : 'dana' }}</span>
                                @endif
                                <span @class(['w-24 text-xs tabular-nums sm:text-right', 'font-semibold text-red-600 dark:text-red-400' => $item->isOverdue(), 'font-medium text-orange-600 dark:text-orange-400' => ! $item->isOverdue() && $item->isDueSoon(), 'text-zinc-500' => ! $item->isOverdue() && ! $item->isDueSoon()]) title="Rok">
                                    @if($item->due_date)
                                        {{ $item->due_date->format('d.m.Y.') }}
                                        @if($item->isOverdue())<span class="sr-only">(kasni {{ abs($daysLeft) }} dana)</span>@endif
                                    @else
                                        bez roka
                                    @endif
                                </span>
                                <x-item-status-menu :item="$item" :editable="$canEdit" align="right" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-700">
                <flux:icon.check-circle class="mx-auto size-10 text-zinc-400" />
                <flux:heading class="mt-3">{{ trim($search) !== '' ? 'Nema stavki koje odgovaraju pretrazi' : 'Nemate otvorenih stavki' }}</flux:heading>
                <flux:text class="mt-1">Stavke za koje postanete odgovorna osoba pojavit će se ovdje.</flux:text>
            </div>
        @endforelse
    </div>
</div>
