<?php

use App\Enums\CaseItemStatus;
use App\Enums\CaseProgressStatus;
use App\Enums\ProjectStatus;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseTemplate;
use App\Models\Institution;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use App\Services\AdministrativeCaseItemService;
use App\Services\AdministrativeCaseService;
use App\Services\AttentionService;
use App\Services\ProjectProgressService;
use App\Services\ProjectWorkflowService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Projekat')] class extends Component {
    public Project $project;

    /** all | in_progress | waiting_response | completed | not_started */
    #[Url(as: 'postupci', except: 'all')]
    public string $caseFilter = 'all';

    /** Brzi unos stavke po postupku. */
    public array $quickItem = [];

    // Desna kolona (inline izmjena)
    public string $currentState = '';
    public string $nextStep = '';

    // Ručna procjena stanja
    public bool $showProgressModal = false;
    public int $manualProgress = 0;
    public string $progressReason = '';

    // Završavanje projekta
    public bool $showCompleteModal = false;
    public string $completionNote = '';
    public array $blockers = [];

    // Postupak (novi ili izmjena)
    public bool $showCaseModal = false;
    public ?int $editingCaseId = null;
    public array $caseForm = ['name' => '', 'institution_id' => '', 'icon' => '', 'template_id' => ''];

    // Članovi
    public bool $showMembersModal = false;
    public string $newMemberId = '';
    public string $newMemberRole = Project::ROLE_MEMBER;

    public const ICONS = ['document-text' => 'Dokument', 'clipboard-document-list' => 'Lista', 'bolt' => 'Struja', 'map' => 'Cesta / trasa', 'map-pin' => 'Lokacija / katastar', 'building-office' => 'Institucija', 'wrench-screwdriver' => 'Radovi', 'shield-check' => 'Saglasnost'];

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
        $this->project = $project;
        $this->syncSideCards();
    }

    // -------------------------------------------------------------- Podaci

    /** Aktivni postupci sa stavkama (bez N+1: stavke, odgovorne osobe, institucije i broj dokumenata). */
    #[Computed]
    public function cases(): Collection
    {
        $cases = $this->project->administrativeCases()
            ->where('is_archived', false)
            ->with([
                'institution:id,name',
                'items' => fn ($query) => $query->where('is_active', true)->with(['responsible:id,name', 'institution:id,name'])->withCount('documents'),
            ])
            ->get();
        $cases->each(fn (AdministrativeCase $case) => $case->setRelation('project', $this->project)
            ->items->each->setRelation('administrativeCase', $case));

        return $cases;
    }

    /** Posljednja aktivnost po postupku (uključujući aktivnosti na njegovim stavkama) — jedan upit. */
    #[Computed]
    public function lastActivityByCase(): Collection
    {
        $itemToCase = $this->cases->flatMap(fn (AdministrativeCase $case) => $case->items->mapWithKeys(fn (AdministrativeCaseItem $item) => [$item->id => $case->id]));

        return ProjectActivity::query()
            ->where('project_id', $this->project->id)
            ->whereIn('subject_type', [(new AdministrativeCase)->getMorphClass(), (new AdministrativeCaseItem)->getMorphClass()])
            ->latest()->latest('id')->limit(500)
            ->get(['id', 'subject_type', 'subject_id', 'title', 'created_at'])
            ->groupBy(fn (ProjectActivity $activity) => $activity->subject_type === (new AdministrativeCase)->getMorphClass() ? $activity->subject_id : ($itemToCase[$activity->subject_id] ?? 0))
            ->map->first();
    }

    #[Computed]
    public function statusCounts(): Collection
    {
        return $this->cases->countBy(fn (AdministrativeCase $case) => $case->progressStatus()->value);
    }

    #[Computed]
    public function visibleCases(): Collection
    {
        return $this->caseFilter === 'all'
            ? $this->cases
            : $this->cases->filter(fn (AdministrativeCase $case) => $case->progressStatus()->value === $this->caseFilter)->values();
    }

    #[Computed]
    public function attention(): Collection
    {
        return app(AttentionService::class)->forProject($this->project->id);
    }

    #[Computed]
    public function recentActivities(): Collection
    {
        return $this->project->activities()->with('user:id,name,grammatical_gender')->latest()->latest('id')->limit(5)->get();
    }

    #[Computed]
    public function recentDocuments(): Collection
    {
        return $this->project->documents()->whereNull('replaced_by_id')->where('is_archived', false)->latest()->latest('id')->limit(4)->get();
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->project->members()->orderByRaw("case when project_user.role = 'manager' then 0 else 1 end")->orderBy('name')->get();
    }

    #[Computed]
    public function progress(): ?int
    {
        return $this->project->setRelation('administrativeCases', $this->cases)->administrativeProgress();
    }

    #[Computed]
    public function canUpdate(): bool
    {
        return auth()->user()->can('update', $this->project);
    }

    /** Osvježavanje kada se stavka promijeni u bočnom panelu. */
    #[On('task-updated')]
    public function refresh(): void
    {
        $this->project->refresh();
        unset($this->cases, $this->lastActivityByCase, $this->statusCounts, $this->visibleCases, $this->attention, $this->recentActivities, $this->recentDocuments, $this->progress);
    }

    // ------------------------------------------------------- Zaglavlje projekta

    public function changeStatus(string $status, ProjectWorkflowService $service): void
    {
        Gate::authorize('update', $this->project);
        validator(['status' => $status], ['status' => ['required', Rule::enum(ProjectStatus::class)]])->validate();

        if ($status === ProjectStatus::Completed->value) {
            $this->blockers = $service->unfinishedRequiredItems($this->project)->map(fn (AdministrativeCaseItem $item) => $item->name.' ('.$item->administrativeCase->name.')')->all();
            $this->completionNote = '';
            $this->showCompleteModal = true;

            return;
        }

        $service->changeStatus($this->project, auth()->user(), ProjectStatus::from($status));
        $this->refresh();
        Flux::toast('Status projekta: '.$this->project->status->label(), variant: 'success');
    }

    public function completeProject(ProjectWorkflowService $service): void
    {
        Gate::authorize('update', $this->project);
        $this->validate(['completionNote' => ['nullable', 'string', 'max:2000']]);
        try {
            $service->changeStatus($this->project, auth()->user(), ProjectStatus::Completed, $this->completionNote ?: null);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['completionNote' => $exception->errors()['completion_note'] ?? $exception->getMessage()]);
        }
        $this->reset('showCompleteModal', 'completionNote', 'blockers');
        $this->refresh();
        Flux::toast('Projekat je označen kao završen.', variant: 'success');
    }

    public function openProgressModal(): void
    {
        Gate::authorize('update', $this->project);
        $this->manualProgress = (int) $this->project->progress;
        $this->progressReason = '';
        $this->showProgressModal = true;
    }

    public function saveProgress(ProjectProgressService $service): void
    {
        Gate::authorize('update', $this->project);
        $this->validate(['manualProgress' => ['required', 'integer', 'between:0,100'], 'progressReason' => ['nullable', 'string', 'max:500']]);
        $service->setManualProgress($this->project, auth()->user(), $this->manualProgress, $this->progressReason ?: 'Ručna procjena stanja');
        $this->showProgressModal = false;
        $this->refresh();
        Flux::toast('Ručna procjena je sačuvana.', variant: 'success');
    }

    public function updatedCurrentState(): void
    {
        $this->saveSideCard('currentState', fn () => app(ProjectWorkflowService::class)->setCurrentState($this->project, auth()->user(), $this->currentState));
    }

    public function updatedNextStep(): void
    {
        $this->saveSideCard('nextStep', fn () => app(ProjectWorkflowService::class)->setNextStep($this->project, auth()->user(), $this->nextStep));
    }

    // ----------------------------------------------------------------- Postupci

    public function openCaseModal(?int $caseId = null): void
    {
        $this->resetValidation();
        $this->editingCaseId = $caseId;
        if ($caseId !== null) {
            $case = $this->findCase($caseId);
            Gate::authorize('update', $case);
            $this->caseForm = ['name' => $case->name, 'institution_id' => (string) ($case->institution_id ?? ''), 'icon' => $case->icon ?? '', 'template_id' => ''];
        } else {
            Gate::authorize('create', AdministrativeCase::class);
            Gate::authorize('update', $this->project);
            $this->caseForm = ['name' => '', 'institution_id' => '', 'icon' => 'document-text', 'template_id' => ''];
        }
        $this->showCaseModal = true;
    }

    public function saveCase(AdministrativeCaseService $service): void
    {
        $data = $this->validate([
            'caseForm.name' => ['required', 'string', 'max:255'],
            'caseForm.institution_id' => ['nullable', 'exists:institutions,id'],
            'caseForm.icon' => ['nullable', Rule::in(array_keys(self::ICONS))],
            'caseForm.template_id' => ['nullable', 'exists:case_templates,id'],
        ], attributes: ['caseForm.name' => 'naziv postupka'])['caseForm'];
        $payload = ['name' => trim($data['name']), 'institution_id' => $data['institution_id'] ?: null, 'icon' => $data['icon'] ?: null];

        if ($this->editingCaseId !== null) {
            $case = $this->findCase($this->editingCaseId);
            Gate::authorize('update', $case);
            $service->update($case, $payload, auth()->user());
            $message = 'Postupak je sačuvan.';
        } else {
            Gate::authorize('create', AdministrativeCase::class);
            Gate::authorize('update', $this->project);
            $payload['project_id'] = $this->project->id;
            $case = filled($data['template_id'])
                ? $service->createFromTemplate($this->project, [...$payload, 'case_template_id' => $data['template_id']], auth()->user())
                : $service->create($payload, auth()->user());
            $this->dispatch('case-created', id: $case->id);
            $message = 'Postupak je dodan.';
        }

        $this->reset('showCaseModal', 'editingCaseId', 'caseForm');
        $this->refresh();
        Flux::toast($message, variant: 'success');
    }

    public function archiveCase(int $caseId, AdministrativeCaseService $service): void
    {
        $case = $this->findCase($caseId);
        Gate::authorize('update', $case);
        $service->setArchived($case, auth()->user(), true);
        $this->refresh();
        Flux::toast('Postupak je arhiviran.');
    }

    /** Drag & drop postupaka. */
    public function sortCases(int|string $caseId, int $position, AdministrativeCaseService $service): void
    {
        $case = $this->findCase((int) $caseId);
        Gate::authorize('update', $case);
        $service->moveTo($case, $position);
        $this->refresh();
    }

    // ------------------------------------------------------------------- Stavke

    public function addItem(int $caseId, AdministrativeCaseItemService $service): void
    {
        $case = $this->findCase($caseId);
        Gate::authorize('update', $case);
        $this->validate(["quickItem.$caseId" => ['required', 'string', 'max:255']], attributes: ["quickItem.$caseId" => 'naziv stavke']);
        $service->create($case, ['name' => trim($this->quickItem[$caseId])], auth()->user());
        $this->quickItem[$caseId] = '';
        $this->refresh();
    }

    /** Promjena statusa direktno iz chipa u tabeli. */
    public function changeItemStatus(int $itemId, string $status, AdministrativeCaseItemService $service): void
    {
        $item = $this->findItem($itemId);
        Gate::authorize('update', $item);
        validator(['status' => $status], ['status' => ['required', Rule::enum(CaseItemStatus::class)]])->validate();
        $service->changeStatus($item, CaseItemStatus::from($status), auth()->user());
        $this->refresh();
    }

    public function duplicateItem(int $itemId, AdministrativeCaseItemService $service): void
    {
        $item = $this->findItem($itemId);
        Gate::authorize('update', $item->administrativeCase);
        $service->duplicate($item, auth()->user());
        $this->refresh();
        Flux::toast('Stavka je duplicirana.', variant: 'success');
    }

    public function deleteItem(int $itemId, AdministrativeCaseItemService $service): void
    {
        $item = $this->findItem($itemId);
        Gate::authorize('update', $item->administrativeCase);
        $service->deactivate($item, auth()->user());
        $this->refresh();
        Flux::toast('Stavka je obrisana.');
    }

    /** Drag & drop stavki unutar postupka. */
    public function sortItems(int|string $itemId, int $position, AdministrativeCaseItemService $service): void
    {
        $item = $this->findItem((int) $itemId);
        Gate::authorize('update', $item->administrativeCase);
        $service->moveTo($item, $position);
        $this->refresh();
    }

    // ------------------------------------------------------------------ Članovi

    public function addMember(ProjectWorkflowService $service): void
    {
        Gate::authorize('update', $this->project);
        $this->validate(['newMemberId' => ['required', 'exists:users,id'], 'newMemberRole' => ['required', Rule::in([Project::ROLE_MANAGER, Project::ROLE_MEMBER])]], attributes: ['newMemberId' => 'korisnik']);
        $service->addMember($this->project, auth()->user(), User::findOrFail($this->newMemberId), $this->newMemberRole);
        $this->reset('newMemberId', 'newMemberRole');
        unset($this->members);
        Flux::toast('Član je dodan.', variant: 'success');
    }

    public function changeMemberRole(int $userId, string $role, ProjectWorkflowService $service): void
    {
        Gate::authorize('update', $this->project);
        abort_unless($this->project->members()->whereKey($userId)->exists(), 404);
        $service->addMember($this->project, auth()->user(), User::findOrFail($userId), $role);
        unset($this->members);
    }

    public function removeMember(int $userId, ProjectWorkflowService $service): void
    {
        Gate::authorize('update', $this->project);
        $service->removeMember($this->project, auth()->user(), User::findOrFail($userId));
        unset($this->members);
        Flux::toast('Član je uklonjen.');
    }

    // ------------------------------------------------------------------ Pomoćno

    public function with(): array
    {
        return [
            'institutions' => $this->showCaseModal ? Institution::orderBy('name')->get(['id', 'name']) : collect(),
            'templates' => $this->showCaseModal && $this->editingCaseId === null ? CaseTemplate::where('is_active', true)->withCount('items')->orderBy('name')->get() : collect(),
            'icons' => self::ICONS,
            'availableUsers' => $this->showMembersModal ? User::whereNotIn('id', $this->members->pluck('id'))->orderBy('name')->get(['id', 'name']) : collect(),
        ];
    }

    private function saveSideCard(string $property, callable $save): void
    {
        Gate::authorize('update', $this->project);
        $this->validate([$property => ['nullable', 'string', 'max:500']]);
        $save();
        $this->refresh();
        unset($this->recentActivities);
        Flux::toast('Sačuvano', variant: 'success', duration: 1500);
    }

    private function syncSideCards(): void
    {
        $this->currentState = $this->project->current_state ?? '';
        $this->nextStep = $this->project->next_step ?? '';
    }

    private function findCase(int $caseId): AdministrativeCase
    {
        return $this->project->administrativeCases()->findOrFail($caseId);
    }

    private function findItem(int $itemId): AdministrativeCaseItem
    {
        $item = AdministrativeCaseItem::with('administrativeCase.project')->where('is_active', true)->findOrFail($itemId);
        abort_unless($item->administrativeCase->project_id === $this->project->id, 404);

        return $item;
    }
}; ?>

@php($canUpdate = $this->canUpdate)
@php($canEditCases = $canUpdate && auth()->user()->can('create', App\Models\AdministrativeCase::class))
@php($warningDays = (int) config('projectflow.attention.warning_days', 3))
@php($itemsWord = fn (int $count) => $count % 10 === 1 && $count % 100 !== 11 ? 'stavka' : (in_array($count % 10, [2, 3, 4], true) && ! in_array($count % 100, [12, 13, 14], true) ? 'stavke' : 'stavki'))

<div class="mx-auto w-full max-w-[1400px]">
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- ============================================================ GLAVNI DIO --}}
        <div class="min-w-0 space-y-5">
            {{-- Zaglavlje --}}
            <header class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <flux:breadcrumbs>
                        <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projekti</flux:breadcrumbs.item>
                        <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
                    </flux:breadcrumbs>
                    <div class="mt-3 flex items-center gap-3">
                        <h1 class="text-3xl font-bold tracking-tight">{{ $project->name }}</h1>
                        @if($canUpdate)
                            <a href="{{ route('projects.edit', $project) }}" wire:navigate class="rounded-lg border border-zinc-200 p-1.5 text-zinc-500 hover:bg-zinc-50 hover:text-zinc-800 dark:border-zinc-700 dark:hover:bg-zinc-800" aria-label="Uredi projekat" title="Uredi projekat"><flux:icon.pencil variant="mini" class="size-4" /></a>
                        @endif
                    </div>
                    @if($project->description)<p class="mt-1 text-sm text-zinc-500">{{ $project->description }}</p>@endif
                    <div class="mt-3 flex flex-wrap gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                        @if($project->location)<span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 dark:border-zinc-700 dark:bg-zinc-900"><flux:icon.map-pin variant="mini" class="size-4 text-zinc-400" />{{ $project->location }}</span>@endif
                        @if($project->category)<span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 dark:border-zinc-700 dark:bg-zinc-900"><flux:icon.cog-6-tooth variant="mini" class="size-4 text-zinc-400" />{{ $project->category->name }}</span>@endif
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 dark:border-zinc-700 dark:bg-zinc-900"><flux:icon.calendar variant="mini" class="size-4 text-zinc-400" />{{ $project->start_date->format('d.m.Y.') }} – {{ $project->planned_end_date?->format('d.m.Y.') ?? 'bez roka' }}</span>
                    </div>
                </div>

                <div class="grid shrink-0 gap-4 sm:grid-cols-[11rem_16rem]">
                    <div>
                        <div class="mb-1 text-xs text-zinc-500">Status projekta</div>
                        @if($canUpdate)
                            <select wire:change="changeStatus($event.target.value)" wire:key="status-{{ $project->status->value }}-{{ (int) $showCompleteModal }}" aria-label="Status projekta"
                                class="w-full rounded-lg border-blue-200 bg-blue-50 py-2 text-sm font-medium text-blue-700 focus:border-blue-500 focus:ring-blue-500 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300">
                                @foreach(ProjectStatus::selectable() as $option)<option value="{{ $option->value }}" @selected($project->status->label() === $option->label())>{{ $option->label() }}</option>@endforeach
                            </select>
                        @else
                            <flux:badge :color="$project->status->color()">{{ $project->status->label() }}</flux:badge>
                        @endif
                    </div>
                    <div class="space-y-3">
                        <div>
                            <div class="mb-1 flex items-center justify-between text-xs text-zinc-500"><span>Administrativni napredak</span><strong class="text-sm text-zinc-900 dark:text-white">{{ $this->progress === null ? '–' : $this->progress.'%' }}</strong></div>
                            <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" role="progressbar" aria-label="Administrativni napredak" aria-valuenow="{{ $this->progress ?? 0 }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-blue-600" style="width: {{ $this->progress ?? 0 }}%"></div></div>
                        </div>
                        <button type="button" @if($canUpdate) wire:click="openProgressModal" @else disabled @endif class="block w-full text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 rounded" title="{{ $canUpdate ? 'Klik za promjenu ručne procjene' : '' }}">
                            <div class="mb-1 flex items-center justify-between text-xs text-zinc-500"><span>Ručna procjena stanja</span><strong class="text-sm text-zinc-500">{{ $project->progress }}%</strong></div>
                            <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"><div class="h-full rounded-full bg-blue-300 dark:bg-blue-700" style="width: {{ $project->progress }}%"></div></div>
                        </button>
                    </div>
                </div>
            </header>

            {{-- Postupci u projektu --}}
            <section class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-5 dark:border-zinc-800 dark:bg-zinc-900"
                x-data="{ open: $persist([]).as('projectflow-open-cases-{{ $project->id }}'), toggle(id) { this.open = this.open.includes(id) ? this.open.filter(i => i !== id) : [...this.open, id] } }"
                x-on:case-created.window="open = [...open, $event.detail.id]">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <flux:icon.clipboard-document-check class="size-6 text-blue-600" />
                        <h2 class="text-lg font-semibold">Postupci u projektu</h2>
                    </div>
                    @if($canEditCases)
                        <flux:button variant="primary" icon="plus" wire:click="openCaseModal">Dodaj postupak</flux:button>
                    @endif
                </div>

                <div class="mt-4 flex flex-wrap gap-2" role="tablist" aria-label="Filter postupaka">
                    @foreach(['all' => 'Svi', CaseProgressStatus::InProgress->value => 'U toku', CaseProgressStatus::WaitingResponse->value => 'Čeka odgovor', CaseProgressStatus::Completed->value => 'Završeno', CaseProgressStatus::NotStarted->value => 'Nije pokrenuto'] as $key => $label)
                        @php($count = $key === 'all' ? $this->cases->count() : $this->statusCounts->get($key, 0))
                        <button type="button" role="tab" aria-selected="{{ $caseFilter === $key ? 'true' : 'false' }}" wire:click="$set('caseFilter', '{{ $key }}')" @class([
                            'rounded-lg border px-3 py-1.5 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
                            'border-blue-200 bg-blue-50 font-medium text-blue-700 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300' => $caseFilter === $key,
                            'border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800' => $caseFilter !== $key,
                        ])>{{ $label }} ({{ $count }})</button>
                    @endforeach
                </div>

                <div class="mt-4 space-y-3" @if($canEditCases && $caseFilter === 'all') wire:sort="sortCases" @endif>
                    @forelse($this->visibleCases as $case)
                        @php($counts = $case->itemCounts())
                        @php($caseStatus = $case->progressStatus())
                        @php($lastActivity = $this->lastActivityByCase->get($case->id))
                        @php($canEditCase = $canEditCases)
                        <article id="predmet-{{ $case->id }}" wire:key="case-{{ $case->id }}" @if($canEditCases && $caseFilter === 'all') wire:sort:item="{{ $case->id }}" @endif class="scroll-mt-20 rounded-xl border border-zinc-200 dark:border-zinc-800">
                            <div class="flex flex-wrap items-center gap-4 p-4 lg:flex-nowrap">
                                @if($canEditCases && $caseFilter === 'all')<span wire:sort:handle class="hidden cursor-grab text-zinc-300 hover:text-zinc-500 sm:block" title="Prevuci za promjenu redoslijeda"><flux:icon.bars-2 variant="mini" class="size-4" /></span>@endif
                                <button type="button" x-on:click="toggle({{ $case->id }})" class="flex min-w-0 flex-1 items-center gap-4 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 rounded-lg" :aria-expanded="open.includes({{ $case->id }}).toString()">
                                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $caseStatus->iconClass() }}"><flux:icon :name="$case->icon ?: 'document-text'" class="size-6" /></span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold">{{ $case->name }}</span>
                                        <span class="block truncate text-sm text-zinc-500">{{ $case->institution?->name ?? 'Bez institucije' }}</span>
                                    </span>
                                </button>
                                <div class="w-40 shrink-0">
                                    <div class="text-sm"><strong>{{ $counts['done'] }} / {{ $counts['total'] }}</strong> {{ $itemsWord($counts['total']) }}</div>
                                    <div class="mt-1.5 flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"><div class="h-full rounded-full bg-blue-600" style="width: {{ $counts['percent'] }}%"></div></div>
                                        <span class="w-9 text-right text-xs text-zinc-500">{{ $counts['percent'] }}%</span>
                                    </div>
                                </div>
                                <div class="w-28 shrink-0"><flux:badge :color="$caseStatus->color()" size="sm">{{ $caseStatus->label() }}</flux:badge></div>
                                <div class="hidden w-48 shrink-0 border-l border-zinc-100 pl-4 text-xs text-zinc-500 md:block dark:border-zinc-800">
                                    <div>Posljednja aktivnost</div>
                                    @if($lastActivity)
                                        <div class="mt-0.5 font-medium text-zinc-800 dark:text-zinc-200">{{ $lastActivity->created_at->format('d.m.Y.') }}</div>
                                        <div class="truncate" title="{{ $lastActivity->title }}">{{ $lastActivity->title }}</div>
                                    @else
                                        <div class="mt-0.5">–</div>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button type="button" x-on:click="$dispatch('open-case', { id: {{ $case->id }} })" class="pf-icon-button" aria-label="Dokumenti i komentari postupka {{ $case->name }}" title="Dokumenti i komentari"><flux:icon.paper-clip variant="mini" class="size-4" /></button>
                                    @if($canEditCase)
                                        <flux:dropdown align="end">
                                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Opcije postupka" />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil-square" wire:click="openCaseModal({{ $case->id }})">Uredi postupak</flux:menu.item>
                                                <flux:menu.item icon="paper-clip" x-on:click="$dispatch('open-case', { id: {{ $case->id }} })">Dokumenti i komentari</flux:menu.item>
                                                <flux:menu.item icon="archive-box" wire:click="archiveCase({{ $case->id }})" wire:confirm="Arhivirati postupak? Stavke i istorija ostaju sačuvane.">Arhiviraj</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif
                                    <button type="button" x-on:click="toggle({{ $case->id }})" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" :aria-label="open.includes({{ $case->id }}) ? 'Zatvori postupak' : 'Otvori postupak'">
                                        <flux:icon.chevron-down variant="mini" class="size-5 transition" x-bind:class="open.includes({{ $case->id }}) && 'rotate-180'" />
                                    </button>
                                </div>
                            </div>

                            {{-- Tabela stavki --}}
                            <div x-show="open.includes({{ $case->id }})" x-cloak class="border-t border-zinc-100 dark:border-zinc-800">
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[900px] text-sm">
                                        <thead>
                                            <tr class="text-left text-xs text-zinc-500">
                                                <th class="w-10 px-3 py-2.5 font-medium">#</th>
                                                <th class="px-3 py-2.5 font-medium">Stavka</th>
                                                <th class="px-3 py-2.5 font-medium">Status</th>
                                                <th class="px-3 py-2.5 font-medium">Institucija / primalac</th>
                                                <th class="px-3 py-2.5 font-medium">Rok</th>
                                                <th class="px-3 py-2.5 font-medium">Predaja</th>
                                                <th class="px-3 py-2.5 font-medium">Odgovor</th>
                                                <th class="px-3 py-2.5 font-medium">Dokumenti</th>
                                                <th class="px-3 py-2.5 font-medium">Odgovorna osoba</th>
                                                <th class="w-14 px-3 py-2.5 text-right font-medium">Akcije</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800" @if($canEditCase) wire:sort="sortItems" @endif>
                                            @forelse($case->items as $item)
                                                @php($canEditItem = $canEditCase || ($item->responsible_user_id === auth()->id() && auth()->user()->role !== App\Enums\UserRole::Viewer))
                                                @php($daysLeft = $item->daysUntilDue())
                                                <tr wire:key="item-{{ $item->id }}" @if($canEditCase) wire:sort:item="{{ $item->id }}" @endif class="group hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40">
                                                    <td class="px-3 py-2.5 text-zinc-500">
                                                        @if($canEditCase)<span wire:sort:handle class="cursor-grab" title="Prevuci za promjenu redoslijeda">{{ $loop->iteration }}</span>@else{{ $loop->iteration }}@endif
                                                    </td>
                                                    <td class="max-w-64 px-3 py-2.5">
                                                        <a href="{{ $item->url() }}" x-on:click="if (! $event.metaKey && ! $event.ctrlKey && window.Livewire?.getByName?.('task-drawer')?.length) { $event.preventDefault(); $dispatch('open-task', { id: {{ $item->id }} }) }" @class(['block truncate font-medium hover:text-blue-600', 'text-zinc-400 line-through' => $item->status === CaseItemStatus::Cancelled])>{{ $item->name }}</a>
                                                    </td>
                                                    <td class="px-3 py-2.5">
                                                        <x-item-status-menu :item="$item" :editable="$canEditItem" />
                                                    </td>
                                                    <td class="px-3 py-2.5 text-zinc-600 dark:text-zinc-300">{{ $item->recipientName() ?? '–' }}</td>
                                                    <td @class(['px-3 py-2.5 tabular-nums', 'font-semibold text-red-600 dark:text-red-400' => $item->isOverdue(), 'font-medium text-orange-600 dark:text-orange-400' => ! $item->isOverdue() && $item->isOpen() && $daysLeft !== null && $daysLeft <= $warningDays, 'text-zinc-600 dark:text-zinc-300' => ! $item->isOverdue() && ($daysLeft === null || $daysLeft > $warningDays || ! $item->isOpen())])>{{ $item->due_date?->format('d.m.Y.') ?? '–' }}</td>
                                                    <td class="px-3 py-2.5 tabular-nums text-zinc-600 dark:text-zinc-300">{{ $item->submitted_at?->format('d.m.Y.') ?? '–' }}</td>
                                                    <td class="px-3 py-2.5 tabular-nums text-zinc-600 dark:text-zinc-300">{{ $item->response_at?->format('d.m.Y.') ?? '–' }}</td>
                                                    <td class="px-3 py-2.5">
                                                        <button type="button" x-on:click="$dispatch('open-task', { id: {{ $item->id }}, section: 'dokumenti' })" @class(['inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs', 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300' => $item->documents_count > 0, 'border-zinc-200 text-zinc-400 dark:border-zinc-700' => $item->documents_count === 0]) aria-label="Dokumenti stavke ({{ $item->documents_count }})">
                                                            <svg viewBox="0 0 16 16" fill="currentColor" class="size-3.5" aria-hidden="true"><path d="M4 2a1.5 1.5 0 0 0-1.5 1.5v9A1.5 1.5 0 0 0 4 14h8a1.5 1.5 0 0 0 1.5-1.5V6.62a1.5 1.5 0 0 0-.44-1.06L9.94 2.44A1.5 1.5 0 0 0 8.88 2H4Z"/></svg>{{ $item->documents_count }}
                                                        </button>
                                                    </td>
                                                    <td class="px-3 py-2.5">
                                                        @if($item->responsible)
                                                            <span class="flex items-center gap-2 whitespace-nowrap"><span class="pf-avatar" aria-hidden="true">{{ $item->responsible->initials() }}</span>{{ $item->responsible->shortName() }}</span>
                                                        @else
                                                            <span class="text-zinc-400">–</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-3 py-2.5 text-right">
                                                        <div x-data="{ open: false }" class="relative inline-block text-left" x-on:keydown.escape="open = false">
                                                            <button type="button" x-on:click="open = ! open" class="pf-icon-button text-lg leading-none" aria-haspopup="true" :aria-expanded="open.toString()" aria-label="Akcije za stavku {{ $item->name }}">⋯</button>
                                                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-on:click="open = false" class="pf-menu right-0" role="menu">
                                                                <button type="button" role="menuitem" x-on:click="$dispatch('open-task', { id: {{ $item->id }} })" class="pf-menu-item">{{ $canEditItem ? 'Uredi' : 'Otvori' }}</button>
                                                                @if($canEditItem)
                                                                    <button type="button" role="menuitem" x-on:click="$dispatch('open-task', { id: {{ $item->id }} })" class="pf-menu-item">Promijeni status</button>
                                                                    <button type="button" role="menuitem" x-on:click="$dispatch('open-task', { id: {{ $item->id }}, section: 'dokumenti' })" class="pf-menu-item">Dodaj dokument</button>
                                                                @endif
                                                                @if($canEditCase)
                                                                    <button type="button" role="menuitem" wire:click="duplicateItem({{ $item->id }})" class="pf-menu-item">Dupliciraj</button>
                                                                    <button type="button" role="menuitem" wire:click="deleteItem({{ $item->id }})" wire:confirm="Obrisati stavku? Istorija i dokumenti ostaju sačuvani." class="pf-menu-item text-red-600">Obriši</button>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="10" class="px-3 py-6 text-center text-sm text-zinc-500">Postupak još nema stavki.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                @if($canEditCase)
                                    <form wire:submit="addItem({{ $case->id }})" class="flex items-center gap-2 border-t border-zinc-100 px-3 py-2 dark:border-zinc-800">
                                        <flux:icon.plus variant="mini" class="size-4 text-zinc-400" />
                                        <input type="text" wire:model="quickItem.{{ $case->id }}" placeholder="Dodaj stavku (naziv, pa Enter)" aria-label="Naziv nove stavke u postupku {{ $case->name }}"
                                            class="flex-1 border-0 bg-transparent px-1 py-1 text-sm placeholder:text-zinc-400 focus:outline-none focus:ring-0" />
                                        <flux:error name="quickItem.{{ $case->id }}" />
                                    </form>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-700">
                            <flux:heading>{{ $caseFilter === 'all' ? 'Projekat još nema postupaka' : 'Nema postupaka s ovim statusom' }}</flux:heading>
                            <flux:text class="mt-1">Postupak je npr. "Urbanistička saglasnost" prema Općini; stavke su koraci koje treba završiti.</flux:text>
                            @if($canEditCases && $caseFilter === 'all')<flux:button class="mt-4" variant="primary" icon="plus" wire:click="openCaseModal">Dodaj postupak</flux:button>@endif
                        </div>
                    @endforelse
                </div>
            </section>
        </div>

        {{-- ============================================================ DESNA KOLONA --}}
        <aside class="space-y-5" aria-label="Pregled projekta">
            {{-- Trenutno stanje i sljedeći korak --}}
            <section class="space-y-3 rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-800 dark:bg-zinc-900">
                @foreach([['currentState', 'Trenutno stanje', 'clock', 'bg-amber-50 text-amber-500 dark:bg-amber-950/40', 'Npr. čeka se odgovor EPBiH…'], ['nextStep', 'Sljedeći korak', 'arrow-right', 'bg-blue-50 text-blue-600 dark:bg-blue-950/40', 'Npr. pripremiti dopunu za Vodovod…']] as [$field, $label, $icon, $iconClass, $placeholder])
                    <div x-data="{ editing: false }" class="flex gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/50">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full {{ $iconClass }}"><flux:icon :name="$icon" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold">{{ $label }}</div>
                            <div x-show="! editing" @if($canUpdate) x-on:click="editing = true; $nextTick(() => $refs.input.focus())" class="cursor-text rounded text-sm text-zinc-600 hover:bg-white dark:text-zinc-300 dark:hover:bg-zinc-800" title="Klik za izmjenu" @else class="text-sm text-zinc-600 dark:text-zinc-300" @endif>
                                {{ $this->{$field} !== '' ? $this->{$field} : ($canUpdate ? 'Klik za unos…' : '–') }}
                            </div>
                            @if($canUpdate)
                                <textarea x-show="editing" x-cloak x-ref="input" wire:model.blur="{{ $field }}" x-on:blur="editing = false" x-on:keydown.enter.prevent="$el.blur()" rows="2" placeholder="{{ $placeholder }}" aria-label="{{ $label }}"
                                    class="mt-1 w-full rounded-md border border-zinc-200 bg-white p-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-zinc-700 dark:bg-zinc-900"></textarea>
                                <flux:error :name="$field" />
                            @endif
                        </div>
                    </div>
                @endforeach
            </section>

            {{-- Zahtijeva pažnju --}}
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-2 font-semibold"><flux:icon.exclamation-triangle class="size-5 text-red-500" />Zahtijeva pažnju</h2>
                    @if($this->attention->isNotEmpty())<span class="flex size-6 items-center justify-center rounded-full bg-red-500 text-xs font-semibold text-white">{{ $this->attention->count() }}</span>@endif
                </div>
                <ul class="mt-3 space-y-2">
                    @forelse($this->attention as $entry)
                        <li wire:key="attention-{{ $entry['item']->id }}">
                            <button type="button" x-on:click="$dispatch('open-task', { id: {{ $entry['item']->id }} })" @class(['flex w-full gap-3 rounded-lg border-l-4 bg-zinc-50 p-2.5 text-left hover:bg-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:bg-zinc-800/50 dark:hover:bg-zinc-800', 'border-red-500' => $entry['type'] === 'overdue', 'border-orange-400' => $entry['type'] !== 'overdue'])>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold">{{ $entry['item']->name }}</span>
                                    <span @class(['block text-xs', 'text-red-600 dark:text-red-400' => $entry['type'] === 'overdue', 'text-zinc-500' => $entry['type'] !== 'overdue'])>{{ $entry['reason'] }}</span>
                                </span>
                                <span class="shrink-0 text-xs text-zinc-500">{{ $entry['date']?->format('d.m.Y.') }}</span>
                            </button>
                        </li>
                    @empty
                        <li class="text-sm text-zinc-500">Sve je pod kontrolom — nema stavki koje kasne ili dugo čekaju.</li>
                    @endforelse
                </ul>
            </section>

            {{-- Posljednje aktivnosti --}}
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-2 font-semibold"><flux:icon.clock class="size-5 text-zinc-500" />Posljednje aktivnosti</h2>
                    <a href="{{ route('projects.history', $project) }}" wire:navigate class="text-sm font-medium text-blue-600 hover:underline">Sve</a>
                </div>
                <ul class="mt-3 space-y-3">
                    @forelse($this->recentActivities as $activity)
                        <li class="flex gap-3 text-sm" wire:key="recent-{{ $activity->id }}">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300"><flux:icon :name="$activity->icon()" variant="micro" class="size-4" /></span>
                            @php($sentence = App\Support\ActivityPresenter::sentence($activity))
                            <span class="line-clamp-2 min-w-0 flex-1" title="{{ $sentence }}">{{ $sentence }}</span>
                            <span class="shrink-0 text-xs text-zinc-500">{{ $activity->created_at->format('d.m.Y.') }}</span>
                        </li>
                    @empty
                        <li class="text-sm text-zinc-500">Još nema aktivnosti.</li>
                    @endforelse
                </ul>
            </section>

            {{-- Dokumenti projekta --}}
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-2 font-semibold"><flux:icon.document-text class="size-5 text-zinc-500" />Dokumenti projekta</h2>
                    <a href="{{ route('projects.documents', $project) }}" wire:navigate class="text-sm font-medium text-blue-600 hover:underline">Sve</a>
                </div>
                <ul class="mt-3 space-y-3">
                    @forelse($this->recentDocuments as $document)
                        <li class="flex items-center gap-3" wire:key="recent-doc-{{ $document->id }}">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $document->iconClass() }}"><flux:icon :name="$document->icon()" variant="mini" class="size-4" /></span>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('documents.show', $document) }}" target="_blank" rel="noopener" class="block truncate text-sm font-medium hover:text-blue-600">{{ $document->name }}</a>
                                <div class="truncate text-xs text-zinc-500">{{ ($document->document_date ?? $document->created_at)->format('d.m.Y.') }}{{ $document->directionLabel() ? ' · '.$document->directionLabel() : '' }} · {{ $document->protocol_number ? 'Br. '.$document->protocol_number : 'Verzija '.$document->version }}</div>
                            </div>
                            <flux:dropdown align="end">
                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Opcije dokumenta" />
                                <flux:menu>
                                    <flux:menu.item icon="eye" :href="route('documents.show', $document)" target="_blank">Otvori</flux:menu.item>
                                    <flux:menu.item icon="arrow-down-tray" :href="route('documents.download', $document)">Preuzmi</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </li>
                    @empty
                        <li class="text-sm text-zinc-500">Još nema dokumenata.</li>
                    @endforelse
                </ul>
            </section>

            {{-- Linkovi projekta --}}
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="mb-3 flex items-center gap-2 font-semibold"><flux:icon.link class="size-5 text-zinc-500" />Linkovi projekta</h2>
                <livewire:links owner-type="project" :owner-id="$project->id" wire:key="project-links-{{ $project->id }}" />
            </section>

            {{-- Članovi projekta --}}
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">Članovi projekta</h2>
                    @if($canUpdate)<button type="button" wire:click="$set('showMembersModal', true)" class="text-sm font-medium text-blue-600 hover:underline">Uredi</button>@endif
                </div>
                <div class="mt-3 flex flex-wrap items-start gap-4">
                    @foreach($this->members as $member)
                        <div class="flex items-center gap-2" wire:key="member-{{ $member->id }}">
                            <flux:avatar circle :name="$member->name" :initials="$member->initials()" />
                            <div class="text-xs leading-tight"><div class="font-medium">{{ $member->shortName() }}</div><div class="text-zinc-500">{{ $member->pivot->role === Project::ROLE_MANAGER ? 'Voditelj projekta' : 'Član' }}</div></div>
                        </div>
                    @endforeach
                    @if($canUpdate)
                        <button type="button" wire:click="$set('showMembersModal', true)" class="flex items-center gap-2 text-xs font-medium text-blue-600" aria-label="Dodaj člana">
                            <span class="flex size-10 items-center justify-center rounded-full border-2 border-dashed border-blue-300"><flux:icon.plus variant="mini" class="size-5" /></span>Dodaj
                        </button>
                    @endif
                </div>
            </section>
        </aside>
    </div>

    {{-- ============================================================ MODALI --}}
    @if($showCaseModal)
        <div class="fixed inset-0 z-40 bg-black/30" wire:click="$set('showCaseModal', false)" aria-hidden="true"></div>
        <div class="fixed inset-x-4 top-16 z-50 mx-auto max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900" role="dialog" aria-modal="true" aria-labelledby="case-modal-title" x-data x-on:keydown.escape.window="$wire.set('showCaseModal', false)">
            <h2 id="case-modal-title" class="text-lg font-semibold">{{ $editingCaseId ? 'Uredi postupak' : 'Novi postupak' }}</h2>
            <form wire:submit="saveCase" class="mt-4 space-y-4">
                <flux:input wire:model="caseForm.name" label="Naziv" placeholder="npr. Urbanistička saglasnost" autofocus />
                <flux:select wire:model="caseForm.institution_id" label="Institucija">
                    <option value="">Bez institucije</option>
                    @foreach($institutions as $institution)<option value="{{ $institution->id }}">{{ $institution->name }}</option>@endforeach
                </flux:select>
                <flux:select wire:model="caseForm.icon" label="Ikona">
                    @foreach($icons as $iconName => $iconLabel)<option value="{{ $iconName }}">{{ $iconLabel }}</option>@endforeach
                </flux:select>
                @if(! $editingCaseId && $templates->isNotEmpty())
                    <flux:select wire:model="caseForm.template_id" label="Šablon stavki (opciono)">
                        <option value="">Bez šablona</option>
                        @foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->name }} ({{ $template->items_count }} stavki)</option>@endforeach
                    </flux:select>
                @endif
                <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('showCaseModal', false)">Odustani</flux:button><flux:button type="submit" variant="primary">Sačuvaj</flux:button></div>
            </form>
        </div>
    @endif

    @if($showProgressModal)
        <div class="fixed inset-0 z-40 bg-black/30" wire:click="$set('showProgressModal', false)" aria-hidden="true"></div>
        <div class="fixed inset-x-4 top-16 z-50 mx-auto max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900" role="dialog" aria-modal="true" aria-labelledby="progress-modal-title" x-data="{ value: $wire.entangle('manualProgress') }" x-on:keydown.escape.window="$wire.set('showProgressModal', false)">
            <h2 id="progress-modal-title" class="text-lg font-semibold">Ručna procjena stanja</h2>
            <form wire:submit="saveProgress" class="mt-4 space-y-4">
                <div>
                    <div class="flex items-center justify-between text-sm"><label for="manual-progress">Procjena</label><strong x-text="value + '%'"></strong></div>
                    <input id="manual-progress" type="range" min="0" max="100" step="5" x-model.number="value" class="mt-2 w-full accent-blue-600" />
                    <flux:error name="manualProgress" />
                </div>
                <flux:input wire:model="progressReason" label="Razlog (opciono)" placeholder="npr. završena projektna dokumentacija" />
                <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('showProgressModal', false)">Odustani</flux:button><flux:button type="submit" variant="primary">Sačuvaj</flux:button></div>
            </form>
        </div>
    @endif

    @if($showCompleteModal)
        <div class="fixed inset-0 z-40 bg-black/30" wire:click="$set('showCompleteModal', false)" aria-hidden="true"></div>
        <div class="fixed inset-x-4 top-16 z-50 mx-auto max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900" role="dialog" aria-modal="true" aria-labelledby="complete-modal-title">
            <h2 id="complete-modal-title" class="text-lg font-semibold">Završavanje projekta</h2>
            @if($blockers !== [])
                <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                    <strong>Obavezne stavke nisu završene:</strong>
                    <ul class="mt-1 list-disc pl-5">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
                </div>
            @endif
            <form wire:submit="completeProject" class="mt-4 space-y-3">
                <flux:textarea wire:model="completionNote" :label="$blockers !== [] ? 'Obrazloženje (obavezno)' : 'Obrazloženje (opciono)'" rows="3" />
                <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('showCompleteModal', false)">Odustani</flux:button><flux:button type="submit" variant="primary">Završi projekat</flux:button></div>
            </form>
        </div>
    @endif

    @if($showMembersModal)
        <div class="fixed inset-0 z-40 bg-black/30" wire:click="$set('showMembersModal', false)" aria-hidden="true"></div>
        <div class="fixed inset-x-4 top-16 z-50 mx-auto max-w-lg rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900" role="dialog" aria-modal="true" aria-labelledby="members-modal-title" x-data x-on:keydown.escape.window="$wire.set('showMembersModal', false)">
            <div class="flex items-center justify-between"><h2 id="members-modal-title" class="text-lg font-semibold">Članovi projekta</h2><flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('showMembersModal', false)" aria-label="Zatvori" /></div>
            <ul class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($this->members as $member)
                    <li class="flex items-center gap-3 py-2" wire:key="member-row-{{ $member->id }}">
                        <flux:avatar size="sm" circle :name="$member->name" :initials="$member->initials()" />
                        <span class="flex-1 text-sm">{{ $member->name }}</span>
                        <select wire:change="changeMemberRole({{ $member->id }}, $event.target.value)" aria-label="Uloga za {{ $member->name }}" class="rounded-md border-zinc-200 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="{{ Project::ROLE_MANAGER }}" @selected($member->pivot->role === Project::ROLE_MANAGER)>Voditelj projekta</option>
                            <option value="{{ Project::ROLE_MEMBER }}" @selected($member->pivot->role !== Project::ROLE_MANAGER)>Član</option>
                        </select>
                        @if($member->id !== $project->responsible_user_id)
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeMember({{ $member->id }})" wire:confirm="Ukloniti {{ $member->name }} iz projekta?" aria-label="Ukloni {{ $member->name }}" />
                        @endif
                    </li>
                @endforeach
            </ul>
            <form wire:submit="addMember" class="mt-4 flex flex-col gap-2 border-t border-zinc-100 pt-4 sm:flex-row sm:items-start dark:border-zinc-800">
                <div class="flex-1">
                    <flux:select wire:model="newMemberId" aria-label="Korisnik"><option value="">Odaberite korisnika</option>@foreach($availableUsers as $availableUser)<option value="{{ $availableUser->id }}">{{ $availableUser->name }}</option>@endforeach</flux:select>
                    <flux:error name="newMemberId" />
                </div>
                <flux:select wire:model="newMemberRole" class="sm:max-w-40" aria-label="Uloga"><option value="{{ Project::ROLE_MEMBER }}">Član</option><option value="{{ Project::ROLE_MANAGER }}">Voditelj projekta</option></flux:select>
                <flux:button type="submit" variant="primary" icon="plus">Dodaj</flux:button>
            </form>
        </div>
    @endif
</div>
