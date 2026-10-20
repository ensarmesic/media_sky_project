<?php

use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCaseItem;
use App\Services\AdministrativeCaseItemService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Moji zadaci')] class extends Component {
    /** open | overdue | done */
    #[Url(except: 'open')]
    public string $filter = 'open';

    /** Zadaci dodijeljeni meni, grupisani po projektu i predmetu. */
    #[Computed]
    public function tasks(): Collection
    {
        return AdministrativeCaseItem::query()
            ->with(['administrativeCase.project', 'administrativeCase.status'])
            ->where('responsible_user_id', auth()->id())
            ->where('is_active', true)
            ->whereHas('administrativeCase', fn (Builder $query) => $query->where('is_archived', false))
            ->when($this->filter === 'open', fn (Builder $query) => $query->whereNotIn('status', CaseItemStatus::closedValues()))
            ->when($this->filter === 'overdue', fn (Builder $query) => $query
                ->whereNotIn('status', CaseItemStatus::closedValues())
                ->whereDate('due_date', '<', today()))
            ->when($this->filter === 'done', fn (Builder $query) => $query
                ->where('status', CaseItemStatus::Completed->value)
                ->where('completed_at', '>=', now()->subDays(30)))
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->limit(200)
            ->get()
            ->groupBy(fn (AdministrativeCaseItem $item) => $item->administrativeCase->project_id);
    }

    #[Computed]
    public function overdueCount(): int
    {
        return AdministrativeCaseItem::query()
            ->where('responsible_user_id', auth()->id())
            ->where('is_active', true)
            ->whereNotIn('status', CaseItemStatus::closedValues())
            ->whereDate('due_date', '<', today())
            ->whereHas('administrativeCase', fn (Builder $query) => $query->where('is_archived', false))
            ->count();
    }

    public function toggleComplete(int $itemId, AdministrativeCaseItemService $service): void
    {
        $item = AdministrativeCaseItem::with('administrativeCase.project')->findOrFail($itemId);
        Gate::authorize('update', $item);
        $service->changeStatus($item, $item->status === CaseItemStatus::Completed ? CaseItemStatus::InPreparation : CaseItemStatus::Completed, auth()->user());
        unset($this->tasks, $this->overdueCount);
    }
}; ?>

<div class="mx-auto flex w-full max-w-4xl flex-col gap-5">
    <div>
        <flux:heading size="xl" level="1">Moji zadaci</flux:heading>
        <flux:subheading class="mt-1">Zadaci iz svih projekata koji su dodijeljeni vama. Klik na zadatak otvara ga u projektu.</flux:subheading>
    </div>

    <div class="flex flex-wrap gap-1">
        @foreach(['open' => 'Otvoreni', 'overdue' => 'Kasne ('.$this->overdueCount.')', 'done' => 'Završeni (30 dana)'] as $filterKey => $filterLabel)
            <button type="button" wire:click="$set('filter', '{{ $filterKey }}')" @class([
                'rounded-lg px-3 py-1.5 text-sm',
                'bg-zinc-900 font-medium text-white dark:bg-white dark:text-zinc-900' => $filter === $filterKey,
                'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $filter !== $filterKey,
            ])>{{ $filterLabel }}</button>
        @endforeach
    </div>

    @forelse($this->tasks as $projectItems)
        @php($project = $projectItems->first()->administrativeCase->project)
        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" wire:key="project-{{ $project->id }}">
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 text-sm font-semibold hover:text-blue-600 dark:border-zinc-800 dark:bg-zinc-800/50">
                <span>{{ $project->name }}</span>
                <flux:icon.chevron-right class="size-4 text-zinc-400" />
            </a>
            @foreach($projectItems as $item)
                @php($isDone = $item->status === CaseItemStatus::Completed)
                <div class="flex items-center gap-3 border-b border-zinc-100 px-4 py-2.5 last:border-0 dark:border-zinc-800" wire:key="my-item-{{ $item->id }}">
                    <button type="button" wire:click="toggleComplete({{ $item->id }})" @class(['flex size-5 shrink-0 items-center justify-center rounded-full border-2', 'border-emerald-500 bg-emerald-500 text-white' => $isDone, 'border-zinc-300 hover:border-emerald-500 dark:border-zinc-600' => ! $isDone]) title="{{ $isDone ? 'Ponovo otvori' : 'Označi završenim' }}">
                        @if($isDone)<flux:icon.check variant="micro" class="size-3.5" />@endif
                    </button>
                    <a href="{{ $item->url() }}" wire:navigate class="min-w-0 flex-1">
                        <div @class(['truncate text-sm', 'text-zinc-400 line-through' => $isDone, 'font-medium hover:text-blue-600' => ! $isDone])>{{ $item->name }}</div>
                        <div class="text-xs text-zinc-500">{{ $item->administrativeCase->name }}</div>
                    </a>
                    @if($item->due_date)
                        <span @class(['shrink-0 text-xs', 'font-medium text-red-600' => $item->isOverdue(), 'text-amber-600' => ! $item->isOverdue() && $item->isDueSoon(), 'text-zinc-500' => ! $item->isOverdue() && ! $item->isDueSoon()])>{{ $item->due_date->format('d.m.Y.') }}</span>
                    @endif
                    <flux:badge :color="$item->status->color()" size="sm" class="hidden sm:inline-flex">{{ $item->status->label() }}</flux:badge>
                </div>
            @endforeach
        </section>
    @empty
        <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-700">
            <flux:icon.check-circle class="mx-auto size-10 text-zinc-400" />
            <flux:heading class="mt-3">{{ $filter === 'done' ? 'Nema završenih zadataka u posljednjih 30 dana' : 'Nemate otvorenih zadataka' }}</flux:heading>
            <flux:text class="mt-1">Zadaci koji vam budu dodijeljeni u predmetima pojavit će se ovdje.</flux:text>
        </div>
    @endforelse
</div>
