<?php

use App\Enums\CaseItemStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\AttentionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Projekti')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' = svi osim arhiviranih; inače vrijednost ProjectStatus (veže se na sidebar). */
    #[Url(except: '')]
    public string $status = '';

    /** activity | deadline | name */
    #[Url(as: 'sort', except: 'activity')]
    public string $sort = 'activity';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function projects()
    {
        Gate::authorize('viewAny', Project::class);
        $status = ProjectStatus::tryFrom($this->status);
        $itemCount = fn (string $alias, ?CaseItemStatus $only) => [
            "items as {$alias}" => fn (Builder $query) => $query
                ->where('administrative_case_items.is_active', true)
                ->where('administrative_cases.is_archived', false)
                ->when($only, fn (Builder $q) => $q->where('administrative_case_items.status', $only->value),
                    fn (Builder $q) => $q->where('administrative_case_items.status', '!=', CaseItemStatus::Cancelled->value)),
        ];

        return Project::visibleTo(auth()->user())
            ->with(['members:id,name', 'category:id,name'])
            ->withCount([...$itemCount('items_total_count', null), ...$itemCount('items_done_count', CaseItemStatus::Completed)])
            ->withMax('activities', 'created_at')
            ->when($status, fn (Builder $query) => $query->whereIn('status', $status->filterValues()),
                fn (Builder $query) => $query->where('status', '!=', ProjectStatus::Archived->value))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where('name', 'like', '%'.trim($this->search).'%')
                ->orWhere('code', 'like', '%'.trim($this->search).'%')
                ->orWhere('location', 'like', '%'.trim($this->search).'%')
                ->orWhere('description', 'like', '%'.trim($this->search).'%')))
            ->when($this->sort === 'name', fn (Builder $query) => $query->orderBy('name'))
            ->when($this->sort === 'deadline', fn (Builder $query) => $query->orderByRaw('planned_end_date is null')->orderBy('planned_end_date'))
            ->when(! in_array($this->sort, ['name', 'deadline'], true), fn (Builder $query) => $query->orderByDesc('activities_max_created_at')->orderByDesc('updated_at'))
            ->paginate(20);
    }

    /** Najviše 3 stavke koje traže pažnju, po projektu sa trenutne stranice. */
    #[Computed]
    public function attention(): Collection
    {
        return app(AttentionService::class)->forProjects($this->projects->pluck('id'), 3);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'sort');
    }
}; ?>

@php($statusTitle = ProjectStatus::tryFrom($status)?->label())
@php($hasFilters = trim($search) !== '' || $status !== '')

<div class="mx-auto flex w-full max-w-6xl flex-col gap-5">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $statusTitle ? 'Projekti – '.mb_strtolower($statusTitle) : 'Projekti' }}</flux:heading>
            <flux:subheading class="mt-1">{{ $this->projects->total() }} {{ $this->projects->total() === 1 ? 'projekat' : 'projekata' }}</flux:subheading>
        </div>
        @can('create', Project::class)
            <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>Dodaj projekat</flux:button>
        @endcan
    </header>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Pretraži po nazivu, opisu ili lokaciji…" class="sm:max-w-sm" aria-label="Pretraga projekata" clearable />
        <flux:select wire:model.live="status" class="sm:max-w-48" aria-label="Status projekta">
            <option value="">Svi (bez arhiviranih)</option>
            @foreach(ProjectStatus::selectable() as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="sort" class="sm:ml-auto sm:max-w-56" aria-label="Sortiranje">
            <option value="activity">Posljednja aktivnost</option>
            <option value="deadline">Rok završetka</option>
            <option value="name">Naziv</option>
        </flux:select>
    </div>

    <div wire:loading.delay.class="opacity-60" class="flex flex-col gap-3 transition-opacity">
        @forelse($this->projects as $project)
            @php($progress = $project->administrativeProgress())
            @php($attention = $this->attention->get($project->id, collect()))
            <article wire:key="project-{{ $project->id }}" class="rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-300 hover:shadow-sm dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-lg font-semibold hover:text-blue-600 focus:outline-none focus-visible:underline">{{ $project->name }}</a>
                            <flux:badge :color="$project->status->color()" size="sm">{{ $project->status->label() }}</flux:badge>
                        </div>
                        @if($project->description)<p class="mt-0.5 line-clamp-2 text-sm text-zinc-500">{{ $project->description }}</p>@endif
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
                            @if($project->location)<span class="inline-flex items-center gap-1"><flux:icon.map-pin variant="micro" class="size-3.5" />{{ $project->location }}</span>@endif
                            @if($project->category)<span class="inline-flex items-center gap-1"><flux:icon.tag variant="micro" class="size-3.5" />{{ $project->category->name }}</span>@endif
                            <span class="inline-flex items-center gap-1"><flux:icon.calendar variant="micro" class="size-3.5" />{{ $project->start_date->format('d.m.Y.') }} – {{ $project->planned_end_date?->format('d.m.Y.') ?? 'bez roka' }}</span>
                        </div>

                        @if($attention->isNotEmpty())
                            <ul class="mt-3 space-y-1" aria-label="Zahtijeva pažnju">
                                @foreach($attention as $entry)
                                    <li class="flex items-center gap-2 text-sm">
                                        <span @class(['size-2 shrink-0 rounded-full', 'bg-red-500' => $entry['type'] === 'overdue', 'bg-orange-500' => $entry['type'] === 'waiting', 'bg-amber-400' => $entry['type'] === 'due_soon'])></span>
                                        <a href="{{ $entry['item']->url() }}" x-on:click="if (window.Livewire?.getByName?.('task-drawer')?.length) { $event.preventDefault(); $dispatch('open-task', { id: {{ $entry['item']->id }} }) }" class="truncate font-medium hover:text-blue-600">{{ $entry['item']->name }}</a>
                                        <span @class(['shrink-0 text-xs', 'text-red-600 dark:text-red-400' => $entry['type'] === 'overdue', 'text-orange-600 dark:text-orange-400' => $entry['type'] !== 'overdue'])>{{ $entry['reason'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-6 lg:w-80 lg:flex-col lg:items-end lg:gap-3">
                        <div class="w-full max-w-56">
                            <div class="flex items-center justify-between text-xs text-zinc-500"><span>Administrativni napredak</span><strong class="text-zinc-900 dark:text-white">{{ $progress === null ? '–' : $progress.'%' }}</strong></div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" role="progressbar" aria-valuenow="{{ $progress ?? 0 }}" aria-valuemin="0" aria-valuemax="100" aria-label="Administrativni napredak">
                                <div class="h-full rounded-full bg-blue-600" style="width: {{ $progress ?? 0 }}%"></div>
                            </div>
                        </div>
                        @if($project->members->isNotEmpty())
                            <div class="flex -space-x-2" aria-label="Članovi projekta">
                                @foreach($project->members->take(5) as $member)
                                    <flux:avatar size="xs" circle :name="$member->name" :initials="$member->initials()" :tooltip="$member->name" class="ring-2 ring-white dark:ring-zinc-900" />
                                @endforeach
                                @if($project->members->count() > 5)
                                    <span class="flex size-6 items-center justify-center rounded-full bg-zinc-100 text-[10px] font-medium text-zinc-600 ring-2 ring-white dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-900">+{{ $project->members->count() - 5 }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-700">
                <flux:icon.folder-open class="mx-auto size-10 text-zinc-400" />
                <flux:heading class="mt-3">{{ $hasFilters ? 'Nijedan projekat ne odgovara filterima' : 'Još nema projekata' }}</flux:heading>
                <div class="mt-4 flex justify-center gap-2">
                    @if($hasFilters)<flux:button wire:click="clearFilters">Očisti filtere</flux:button>@endif
                    @can('create', Project::class)<flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>Dodaj projekat</flux:button>@endcan
                </div>
            </div>
        @endforelse
    </div>

    {{ $this->projects->links() }}
</div>
