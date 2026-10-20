<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Projekti')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    /** 'active' = svi osim završenih i arhiviranih, '' = svi, inače konkretan status. */
    #[Url]
    public string $status = 'active';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function projects()
    {
        Gate::authorize('viewAny', Project::class);

        return Project::visibleTo(auth()->user())
            ->with([
                'responsible',
                'administrativeCases' => fn ($query) => $query->where('is_archived', false),
                'administrativeCases.items' => fn ($query) => $query->where('is_active', true)->where('status', '!=', \App\Enums\CaseItemStatus::Cancelled->value),
            ])
            ->when($this->search, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('code', 'like', "%{$this->search}%")
                ->orWhere('location', 'like', "%{$this->search}%")))
            ->when($this->status === 'active', fn (Builder $query) => $query->whereNotIn('status', [ProjectStatus::Completed->value, ProjectStatus::Archived->value]))
            ->when($this->status !== 'active' && $this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->latest('updated_at')
            ->paginate(20);
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-5">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">Projekti</flux:heading>
            <flux:subheading class="mt-1">Otvorite projekat da vidite njegove predmete i zadatke</flux:subheading>
        </div>
        @can('create', Project::class)
            <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>Dodaj projekat</flux:button>
        @endcan
    </header>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Pretraži po nazivu, oznaci ili lokaciji…" class="sm:max-w-md" />
        <flux:select wire:model.live="status" class="sm:max-w-56">
            <option value="active">Aktivni projekti</option>
            <option value="">Svi projekti</option>
            @foreach(ProjectStatus::cases() as $statusOption)
                <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="hidden grid-cols-[minmax(220px,2fr)_130px_90px_190px_110px] gap-4 border-b border-zinc-200 bg-zinc-50 px-5 py-3 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 md:grid">
            <span>Projekat</span><span>Status</span><span>Predmeti</span><span>Zadaci</span><span>Kasni</span>
        </div>
        @forelse($this->projects as $project)
            @php($items = $project->administrativeCases->flatMap->items)
            @php($done = $items->where('status', App\Enums\CaseItemStatus::Completed)->count())
            @php($overdue = $items->filter->isOverdue()->count())
            @php($progress = $items->isNotEmpty() ? (int) round($done / $items->count() * 100) : 0)
            <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="project-{{ $project->id }}" class="grid gap-3 border-b border-zinc-100 px-5 py-4 last:border-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40 md:grid-cols-[minmax(220px,2fr)_130px_90px_190px_110px] md:items-center">
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ $project->name }}</div>
                    <div class="mt-0.5 truncate text-xs text-zinc-500">{{ $project->code }}{{ $project->location ? ' · '.$project->location : '' }} · {{ $project->responsible->name }}</div>
                </div>
                <div><flux:badge :color="$project->status->color()" size="sm">{{ $project->status->label() }}</flux:badge></div>
                <div class="text-sm text-zinc-600 dark:text-zinc-300"><span class="md:hidden">Predmeti: </span>{{ $project->administrativeCases->count() }}</div>
                <div>
                    <div class="text-sm">{{ $items->isEmpty() ? 'Nema zadataka' : $done.'/'.$items->count().' završeno' }}</div>
                    <div class="mt-1.5 h-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800"><div class="h-full rounded-full bg-blue-600" style="width: {{ $progress }}%"></div></div>
                </div>
                <div class="text-sm {{ $overdue ? 'font-medium text-red-600' : 'text-zinc-400' }}">{{ $overdue ? $overdue.' kasni' : '—' }}</div>
            </a>
        @empty
            <div class="px-6 py-16 text-center">
                <flux:heading>Nema projekata</flux:heading>
                <flux:text class="mt-1">{{ $search || $status !== 'active' ? 'Nijedan projekat ne odgovara filterima.' : 'Dodajte prvi projekat za početak rada.' }}</flux:text>
            </div>
        @endforelse
    </div>
    {{ $this->projects->links() }}
</div>
