<?php

use App\Enums\CaseItemStatus;
use App\Enums\ProjectStatus;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Project;
use App\Services\AdministrativeCaseItemService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Projekti')] class extends Component {
    /** Pretraga po nazivu projekta, predmeta ili zadatka. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' (sve aktivne faze), status zadatka ili 'overdue'. */
    #[Url(as: 'faza', except: '')]
    public string $phase = '';

    #[Url(as: 'moji', except: false)]
    public bool $onlyMine = false;

    #[Url(as: 'projekat', except: '')]
    public string $projectId = '';

    #[Url(as: 'zavrseni', except: false)]
    public bool $showCompleted = false;

    /** Naziv novog zadatka po predmetu (brzi unos). */
    public array $quickTask = [];

    /** Projekti koje korisnik vidi (za filter). */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::visibleTo(auth()->user())
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Projekti → predmeti → aktivni zadaci, filtrirano. Ukupno 3 upita:
     * projekti, predmeti (s brojačima) i aktivni zadaci (s brojačima komentara/priloga).
     */
    #[Computed]
    public function groups(): Collection
    {
        Gate::authorize('viewAny', Project::class);
        $search = trim($this->search);

        $projects = Project::visibleTo(auth()->user())
            ->where('status', '!=', ProjectStatus::Archived->value)
            ->when($this->projectId !== '', fn (Builder $query) => $query->whereKey($this->projectId))
            ->orderBy('name')
            ->get(['id', 'name', 'location', 'status']);

        $cases = AdministrativeCase::query()
            ->whereIn('project_id', $projects->modelKeys())
            ->where('is_archived', false)
            ->withCount([
                'items as total_count' => fn (Builder $query) => $query->where('is_active', true)->where('status', '!=', CaseItemStatus::Cancelled->value),
                'items as done_count' => fn (Builder $query) => $query->where('is_active', true)->where('status', CaseItemStatus::Completed->value),
            ])
            ->orderBy('position')->orderBy('id')
            ->get(['id', 'project_id', 'name', 'position']);

        $items = AdministrativeCaseItem::query()
            ->open()
            ->whereIn('administrative_case_id', $cases->modelKeys())
            ->with('responsible:id,name')
            ->withCount(['comments', 'attachments'])
            ->when($this->phase === 'overdue', fn (Builder $query) => $query->whereDate('due_date', '<', today()))
            ->when($this->phase !== '' && $this->phase !== 'overdue', fn (Builder $query) => $query->where('status', $this->phase))
            ->when($this->onlyMine, fn (Builder $query) => $query->where('responsible_user_id', auth()->id()))
            ->orderBy('position')->orderBy('id')
            ->get()
            ->groupBy('administrative_case_id');

        $matches = fn (string $text) => $search === '' || mb_stripos($text, $search) !== false;
        $filtersActive = $this->phase !== '' || $this->onlyMine;

        return $projects->map(function (Project $project) use ($cases, $items, $matches, $filtersActive) {
            $projectMatches = $matches($project->name);
            $projectCases = $cases->where('project_id', $project->id)->map(function (AdministrativeCase $case) use ($project, $items, $matches, $projectMatches) {
                // Već učitane veze postavljamo ručno da linkovi i provjere ovlasti ne prave dodatne upite.
                $case->setRelation('project', $project);
                $caseItems = $items->get($case->id, collect())->each(fn (AdministrativeCaseItem $item) => $item->setRelation('administrativeCase', $case));
                if (! $projectMatches && ! $matches($case->name)) {
                    $caseItems = $caseItems->filter(fn (AdministrativeCaseItem $item) => $matches($item->name))->values();
                }

                return ['case' => $case, 'items' => $caseItems, 'matches' => $projectMatches || $matches($case->name)];
            })->filter(fn (array $group) => $group['items']->isNotEmpty()
                // Predmeti bez aktivnih zadataka samo uz prekidač (i kad nijedan filter ne skriva zadatke).
                || ($this->showCompleted && ! $filtersActive && $group['matches']))
                ->values();

            return ['project' => $project, 'cases' => $projectCases];
        })->filter(fn (array $group) => $group['cases']->isNotEmpty())->values();
    }

    /** Brzi unos zadatka: samo naziv + Enter, status "Novo". */
    public function addTask(int $caseId, AdministrativeCaseItemService $service): void
    {
        $case = AdministrativeCase::with('project')->findOrFail($caseId);
        Gate::authorize('update', $case);
        $this->validate(["quickTask.$caseId" => ['required', 'string', 'max:255']], attributes: ["quickTask.$caseId" => 'naziv zadatka']);

        $service->create($case, ['name' => trim($this->quickTask[$caseId])], auth()->user());
        $this->quickTask[$caseId] = '';
        unset($this->groups);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'phase', 'onlyMine', 'projectId');
    }

    /** Osvježavanje kad se zadatak promijeni u detalju zadatka. */
    #[On('task-updated')]
    public function refreshTasks(): void
    {
        unset($this->groups);
    }
}; ?>

@php($phases = ['' => 'Sve', CaseItemStatus::InProgress->value => 'U toku', CaseItemStatus::Waiting->value => 'Na čekanju', CaseItemStatus::InReview->value => 'Na pregledu', 'overdue' => 'Kasni'])
@php($hasFilters = $search !== '' || $phase !== '' || $onlyMine || $projectId !== '')

<div class="mx-auto flex w-full max-w-7xl flex-col gap-5">
    <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">Projekti</flux:heading>
            <flux:subheading class="mt-1">Aktivni zadaci po predmetima svih vaših projekata</flux:subheading>
        </div>
        @can('create', App\Models\Project::class)
            <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>Novi projekat</flux:button>
        @endcan
    </header>

    {{-- Filteri (sve ide u URL) --}}
    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900 lg:flex-row lg:items-center">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Pretraži projekte, predmete i zadatke…" class="lg:max-w-xs" aria-label="Pretraga" clearable />
        <div class="flex flex-wrap gap-1" role="group" aria-label="Faza">
            @foreach($phases as $phaseKey => $phaseLabel)
                <button type="button" wire:click="$set('phase', '{{ $phaseKey }}')" aria-pressed="{{ $phase === (string) $phaseKey ? 'true' : 'false' }}" @class([
                    'rounded-full px-3 py-1 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
                    'bg-blue-600 font-medium text-white' => $phase === (string) $phaseKey,
                    'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' => $phase !== (string) $phaseKey,
                ])>{{ $phaseLabel }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-3 lg:ml-auto">
            <flux:select wire:model.live="projectId" size="sm" class="min-w-44" aria-label="Projekat">
                <option value="">Svi projekti</option>
                @foreach($this->projectOptions as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
            </flux:select>
            <flux:switch wire:model.live="onlyMine" label="Samo moji" align="left" />
            <flux:switch wire:model.live="showCompleted" label="Prikaži i završene predmete" align="left" />
        </div>
    </div>

    <div wire:loading.delay.class="opacity-60" class="flex flex-col gap-8 transition-opacity">
        @forelse($this->groups as $group)
            @php($project = $group['project'])
            <section wire:key="project-{{ $project->id }}" aria-labelledby="project-heading-{{ $project->id }}">
                <a href="{{ route('projects.show', $project) }}" wire:navigate class="group inline-block rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                    <h2 id="project-heading-{{ $project->id }}" class="text-sm font-semibold uppercase tracking-wide text-zinc-700 group-hover:text-blue-600 dark:text-zinc-200">{{ $project->name }}</h2>
                    @if($project->location)<div class="text-xs text-zinc-500">{{ $project->location }}</div>@endif
                </a>

                <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($group['cases'] as $caseGroup)
                        @php($case = $caseGroup['case'])
                        @php($progress = $case->total_count > 0 ? (int) round($case->done_count / $case->total_count * 100) : 0)
                        @php($activeCount = $case->total_count - $case->done_count)
                        <article wire:key="case-{{ $case->id }}" class="flex flex-col rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="p-4 pb-3">
                                <div class="flex items-start justify-between gap-2">
                                    <a href="{{ route('cases.show', $case) }}" wire:navigate class="font-semibold leading-snug hover:text-blue-600">{{ $case->name }}</a>
                                    <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" title="Aktivni zadaci">{{ $activeCount }} {{ $activeCount === 1 ? 'aktivan' : 'aktivnih' }}</span>
                                </div>
                                <div class="mt-3 h-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100" aria-label="Završeno {{ $case->done_count }} od {{ $case->total_count }}">
                                    <div class="h-full rounded-full bg-blue-600" style="width: {{ $progress }}%"></div>
                                </div>
                                <div class="mt-1 text-xs text-zinc-500">{{ $case->done_count }}/{{ $case->total_count }} završeno</div>
                            </div>

                            <ul class="flex-1 divide-y divide-zinc-100 border-t border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
                                @forelse($caseGroup['items'] as $item)
                                    <li wire:key="task-{{ $item->id }}">
                                        <x-task-row :item="$item" />
                                    </li>
                                @empty
                                    <li class="px-4 py-3 text-sm text-zinc-500">Nema aktivnih zadataka.</li>
                                @endforelse
                            </ul>

                            @can('update', $case)
                                <div x-data="{ open: false }" class="border-t border-zinc-100 p-2 dark:border-zinc-800">
                                    <button type="button" x-show="! open" x-on:click="open = true; $nextTick(() => $refs.input.focus())" class="w-full rounded-md px-2 py-1.5 text-left text-sm text-zinc-500 hover:bg-zinc-50 hover:text-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-zinc-800">+ Dodaj zadatak</button>
                                    <form x-show="open" x-cloak wire:submit="addTask({{ $case->id }})" x-on:keydown.escape="open = false" class="px-1">
                                        <input x-ref="input" wire:model="quickTask.{{ $case->id }}" type="text" placeholder="Naziv zadatka, pa Enter" aria-label="Naziv novog zadatka"
                                            class="w-full rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-zinc-700 dark:bg-zinc-800" />
                                        <flux:error name="quickTask.{{ $case->id }}" />
                                    </form>
                                </div>
                            @endcan
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-700">
                <flux:icon.check-circle class="mx-auto size-10 text-zinc-400" />
                <flux:heading class="mt-3">Nema aktivnih zadataka</flux:heading>
                <flux:text class="mt-1">{{ $hasFilters ? 'Nijedan zadatak ne odgovara odabranim filterima.' : 'Svi zadaci su završeni ili još nisu dodani.' }}</flux:text>
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    @if($hasFilters)
                        <flux:button wire:click="clearFilters">Očisti filtere</flux:button>
                    @elseif(! $showCompleted)
                        <flux:button icon="plus" wire:click="$set('showCompleted', true)">Dodaj zadatak u predmet</flux:button>
                    @endif
                    @if($this->projectOptions->isEmpty())
                        @can('create', App\Models\Project::class)
                            <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>Novi projekat</flux:button>
                        @endcan
                    @else
                        <flux:dropdown>
                            <flux:button icon:trailing="chevron-down">Otvori projekat</flux:button>
                            <flux:menu>
                                @foreach($this->projectOptions as $option)<flux:menu.item :href="route('projects.show', $option->id)" wire:navigate>{{ $option->name }}</flux:menu.item>@endforeach
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            </div>
        @endforelse
    </div>
</div>
