<?php

use App\Enums\CaseItemStatus;
use App\Enums\CaseOutcome;
use App\Enums\DocumentDirection;
use App\Enums\ProjectStatus;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\CaseStatus;
use App\Models\CaseTemplate;
use App\Models\CaseType;
use App\Models\Institution;
use App\Models\Project;
use App\Models\User;
use App\Services\AdministrativeCaseItemService;
use App\Services\AdministrativeCaseService;
use App\Services\DocumentService;
use App\Services\ProjectActivityService;
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
use Livewire\WithFileUploads;

new #[Title('Projekat')] class extends Component {
    use WithFileUploads;

    public Project $project;

    #[Url(as: 'prikaz', except: 'predmeti')]
    public string $tab = 'predmeti';

    /** open | all | overdue | mine */
    #[Url(except: 'open')]
    public string $filter = 'open';

    public string $search = '';
    public bool $showArchived = false;
    /** @var array<int, bool> */
    public array $collapsed = [];
    /** @var array<int, array<string, string>> */
    public array $newTask = [];


    // Modal predmeta (novi ili izmjena)
    public bool $showCaseModal = false;
    public ?int $editingCaseId = null;
    public string $caseMode = 'manual';
    public ?int $templateId = null;
    public array $caseForm = [];

    // Status projekta
    public bool $showCompleteModal = false;
    public string $completionNote = '';
    public array $blockersForCompletion = [];

    // Dokumenti
    public string $docMode = 'upload';
    public $document;
    public string $documentName = '';
    public string $documentType = '';
    public string $documentCaseId = '';
    public string $documentDescription = '';
    public array $linkForm = [];
    public array $protocolForm = [];
    public string $documentSearch = '';
    public bool $showArchivedDocuments = false;
    public ?int $replaceDocumentId = null;
    public $versionFile;
    public ?int $editingReferenceId = null;
    public array $referenceForm = [];

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
        $this->project = $project;
        $this->refreshProject();

    }

    // ---------------------------------------------------------------- Prikaz

    /** Predmeti projekta, filtrirani po arhivi i pretrazi (po nazivu predmeta ili zadatka). */
    #[Computed]
    public function cases(): Collection
    {
        $search = mb_strtolower(trim($this->search));

        return $this->project->administrativeCases
            ->filter(fn (AdministrativeCase $case) => (bool) $case->is_archived === $this->showArchived)
            ->filter(fn (AdministrativeCase $case) => $search === ''
                || str_contains(mb_strtolower($case->name), $search)
                || $case->items->contains(fn (AdministrativeCaseItem $item) => str_contains(mb_strtolower($item->name), $search)))
            ->filter(fn (AdministrativeCase $case) => in_array($this->filter, ['open', 'all'], true) || $this->visibleItems($case)->isNotEmpty())
            ->values();
    }

    /** Zadaci predmeta prema odabranom filteru. */
    public function visibleItems(AdministrativeCase $case): Collection
    {
        $search = mb_strtolower(trim($this->search));
        $caseMatches = $search === '' || str_contains(mb_strtolower($case->name), $search);

        return $case->items
            ->filter(fn (AdministrativeCaseItem $item) => $caseMatches || str_contains(mb_strtolower($item->name), $search))
            ->filter(fn (AdministrativeCaseItem $item) => match ($this->filter) {
                'open' => $item->isOpen(),
                'overdue' => $item->isOverdue(),
                'mine' => $item->responsible_user_id === auth()->id() && $item->isOpen(),
                default => true,
            })
            ->values();
    }

    /** @return array{total: int, done: int, overdue: int, mine: int} */
    #[Computed]
    public function stats(): array
    {
        $items = $this->project->administrativeCases
            ->where('is_archived', false)
            ->flatMap->items
            ->reject(fn (AdministrativeCaseItem $item) => $item->status === CaseItemStatus::Cancelled);

        return [
            'total' => $items->count(),
            'done' => $items->where('status', CaseItemStatus::Completed)->count(),
            'overdue' => $items->filter->isOverdue()->count(),
            'mine' => $items->filter(fn (AdministrativeCaseItem $item) => $item->responsible_user_id === auth()->id() && $item->isOpen())->count(),
        ];
    }

    /** Osvježavanje nakon izmjene u detalju zadatka (drawer). */
    #[On('task-updated')]
    public function refreshAfterTaskChange(): void
    {
        $this->refreshProject();
    }

    public function toggleCase(int $caseId): void
    {
        $this->collapsed[$caseId] = ! ($this->collapsed[$caseId] ?? false);
    }

    // ------------------------------------------------------- Status projekta

    public function changeProjectStatus(string $newStatus, ProjectWorkflowService $service): void
    {
        Gate::authorize('update', $this->project);
        validator(['status' => $newStatus], ['status' => ['required', Rule::enum(ProjectStatus::class)]])->validate();

        if ($newStatus === ProjectStatus::Completed->value) {
            $this->completionNote = '';
            $this->resetValidation();
            $this->blockersForCompletion = $service->unfinishedRequiredItems($this->project)
                ->map(fn (AdministrativeCaseItem $item) => $item->name.' ('.$item->administrativeCase->name.')')->all();
            $this->showCompleteModal = true;

            return;
        }

        $service->changeStatus($this->project, auth()->user(), ProjectStatus::from($newStatus));
        $this->refreshProject();
        Flux::toast('Status projekta: '.$this->project->status->label(), variant: 'success');
    }

    public function completeProject(ProjectWorkflowService $service): void
    {
        Gate::authorize('update', $this->project);
        $this->validate(['completionNote' => ['nullable', 'string', 'max:2000']]);
        $service->changeStatus($this->project, auth()->user(), ProjectStatus::Completed, $this->completionNote ?: null);
        $this->reset('showCompleteModal', 'completionNote', 'blockersForCompletion');
        $this->refreshProject();
        Flux::toast('Projekat je označen kao završen.', variant: 'success');
    }

    // --------------------------------------------------------------- Predmeti

    public function openCaseModal(?int $caseId = null): void
    {
        $this->resetValidation();
        $this->editingCaseId = $caseId;
        $this->caseMode = 'manual';
        $this->templateId = null;

        if ($caseId !== null) {
            $case = $this->findCase($caseId);
            Gate::authorize('update', $case);
            $this->caseForm = [
                'name' => $case->name, 'description' => $case->description ?? '',
                'case_type_id' => (string) $case->case_type_id, 'institution_id' => (string) ($case->institution_id ?? ''),
                'responsible_user_id' => (string) $case->responsible_user_id, 'case_status_id' => (string) $case->case_status_id,
                'started_at' => $case->started_at->format('Y-m-d'), 'response_due_at' => $case->response_due_at?->format('Y-m-d') ?? '',
                'reminder_days_before' => (string) ($case->reminder_days_before ?? ''),
            ];
        } else {
            Gate::authorize('create', AdministrativeCase::class);
            Gate::authorize('update', $this->project);
            $this->caseForm = [
                'name' => '', 'description' => '', 'case_type_id' => '', 'institution_id' => '',
                'responsible_user_id' => (string) auth()->id(),
                'case_status_id' => (string) (CaseStatus::where('is_active', true)->orderBy('position')->value('id') ?? ''),
                'started_at' => now()->toDateString(), 'response_due_at' => '', 'reminder_days_before' => '3',
            ];
        }

        $this->showCaseModal = true;
    }

    public function saveCase(AdministrativeCaseService $service): void
    {
        $fromTemplate = $this->editingCaseId === null && $this->caseMode === 'template';
        $data = $this->validate([
            'caseForm.name' => ['required', 'string', 'max:255'],
            'caseForm.description' => ['nullable', 'string', 'max:5000'],
            'caseForm.case_type_id' => [$fromTemplate ? 'nullable' : 'required', 'exists:case_types,id'],
            'caseForm.institution_id' => ['nullable', 'exists:institutions,id'],
            'caseForm.responsible_user_id' => ['required', 'exists:users,id'],
            'caseForm.case_status_id' => ['required', 'exists:case_statuses,id'],
            'caseForm.started_at' => ['required', 'date'],
            'caseForm.response_due_at' => ['nullable', 'date', 'after_or_equal:caseForm.started_at'],
            'caseForm.reminder_days_before' => ['nullable', 'integer', 'between:0,365'],
            'templateId' => [$fromTemplate ? 'required' : 'nullable', 'exists:case_templates,id'],
        ])['caseForm'];
        $data = array_map(fn ($value) => $value === '' ? null : $value, $data);

        if ($this->editingCaseId !== null) {
            $case = $this->findCase($this->editingCaseId);
            Gate::authorize('update', $case);
            $this->withErrorPrefix('caseForm.', fn () => $service->update($case, $data, auth()->user()));
            $message = 'Predmet je sačuvan.';
        } else {
            Gate::authorize('create', AdministrativeCase::class);
            Gate::authorize('update', $this->project);
            $payload = [...$data, 'project_id' => $this->project->id];
            $case = $fromTemplate
                ? $this->withErrorPrefix('caseForm.', fn () => $service->createFromTemplate($this->project, [...$payload, 'case_template_id' => $this->templateId], auth()->user()))
                : $service->create($payload, auth()->user());
            $this->collapsed[$case->id] = false;
            $message = 'Predmet je kreiran.';
        }

        $this->reset('showCaseModal', 'editingCaseId', 'caseForm', 'templateId', 'caseMode');
        $this->refreshProject();
        Flux::toast($message, variant: 'success');
    }

    public function duplicateCase(int $caseId, AdministrativeCaseService $service): void
    {
        $case = $this->findCase($caseId);
        Gate::authorize('create', AdministrativeCase::class);
        Gate::authorize('update', $case);
        $service->duplicate($case, auth()->user());
        $this->refreshProject();
        Flux::toast('Predmet je dupliran sa svim zadacima.', variant: 'success');
    }

    public function moveCase(int $caseId, string $direction, AdministrativeCaseService $service): void
    {
        $case = $this->findCase($caseId);
        Gate::authorize('update', $case);
        $service->movePosition($case, $direction === 'up' ? 'up' : 'down');
        $this->refreshProject();
    }

    public function archiveCase(int $caseId, AdministrativeCaseService $service): void
    {
        $case = $this->findCase($caseId);
        Gate::authorize('update', $case);
        $service->setArchived($case, auth()->user(), ! $case->is_archived);
        $this->refreshProject();
        Flux::toast($case->is_archived ? 'Predmet je vraćen iz arhive.' : 'Predmet je arhiviran.', variant: 'success');
    }

    // ---------------------------------------------------------------- Zadaci

    public function addTask(int $caseId, AdministrativeCaseItemService $service): void
    {
        $case = $this->findCase($caseId);
        Gate::authorize('update', $case);
        $data = $this->validate([
            "newTask.$caseId.name" => ['required', 'string', 'max:255'],
            "newTask.$caseId.responsible_user_id" => ['nullable', 'exists:users,id'],
            "newTask.$caseId.due_date" => ['nullable', 'date'],
        ], attributes: ["newTask.$caseId.name" => 'naziv zadatka'])['newTask'][$caseId];

        $service->create($case, [
            'name' => trim($data['name']),
            'responsible_user_id' => $data['responsible_user_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ], auth()->user());

        $this->newTask[$caseId] = ['name' => '', 'responsible_user_id' => $data['responsible_user_id'] ?? '', 'due_date' => ''];
        $this->refreshProject();
    }

    /** Brzo označavanje zadatka završenim / ponovno otvaranje. */
    public function toggleComplete(int $itemId, AdministrativeCaseItemService $service): void
    {
        $item = $this->findItem($itemId);
        Gate::authorize('update', $item);
        $service->changeStatus($item, $item->status === CaseItemStatus::Completed ? CaseItemStatus::InPreparation : CaseItemStatus::Completed, auth()->user());
        $this->refreshProject();
    }

    public function changeItemStatus(int $itemId, string $status, AdministrativeCaseItemService $service): void
    {
        $item = $this->findItem($itemId);
        Gate::authorize('update', $item);
        validator(['status' => $status], ['status' => ['required', Rule::enum(CaseItemStatus::class)]])->validate();
        $service->changeStatus($item, CaseItemStatus::from($status), auth()->user());
        $this->refreshProject();
    }

    // ------------------------------------------------------------- Dokumenti

    public function uploadDocument(DocumentService $service): void
    {
        $this->validate([
            'document' => ['required', 'file', 'mimes:'.config('projectflow.documents.allowed_mimes'), 'max:'.config('projectflow.documents.max_size')],
            'documentName' => ['nullable', 'string', 'max:255'],
            'documentType' => ['nullable', 'string', 'max:100'],
            'documentCaseId' => ['nullable', Rule::exists('administrative_cases', 'id')->where('project_id', $this->project->id)],
            'documentDescription' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->storeFile($this->project, auth()->user(), $this->document, [
            'name' => $this->documentName,
            'description' => $this->documentDescription,
            'document_type' => $this->documentType,
            'administrative_case_id' => $this->documentCaseId ?: null,
        ]);
        $this->reset('document', 'documentName', 'documentType', 'documentCaseId', 'documentDescription');
        $this->refreshProject();
        Flux::toast('Dokument je dodan.', variant: 'success');
    }

    public function addLink(DocumentService $service): void
    {
        $this->withErrorPrefix('linkForm.', fn () => $service->storeExternalLink($this->project, auth()->user(), $this->nullIfEmpty($this->linkForm)));
        $this->reset('linkForm');
        $this->refreshProject();
        Flux::toast('Link je evidentiran.', variant: 'success');
    }

    public function addProtocol(DocumentService $service): void
    {
        $this->withErrorPrefix('protocolForm.', fn () => $service->storeProtocolReference($this->project, auth()->user(), $this->nullIfEmpty($this->protocolForm)));
        $this->reset('protocolForm');
        $this->refreshProject();
        Flux::toast('Dokument iz protokola je evidentiran.', variant: 'success');
    }

    public function replaceDocumentVersion(DocumentService $service): void
    {
        $document = $this->project->documents()->findOrFail($this->replaceDocumentId);
        $this->validate(['versionFile' => ['required', 'file']]);
        $this->withErrorPrefix('', fn () => $service->replaceFileVersion($document, auth()->user(), $this->versionFile), ['file' => 'versionFile']);
        $this->reset('replaceDocumentId', 'versionFile');
        $this->refreshProject();
        Flux::toast('Dokument je zamijenjen novom verzijom.', variant: 'success');
    }

    public function archiveDocument(int $id, DocumentService $service): void
    {
        $document = $this->project->documents()->findOrFail($id);
        $service->archiveDocument($document, auth()->user(), ! $document->is_archived);
        $this->refreshProject();
    }

    public function deleteDocument(int $id, DocumentService $service): void
    {
        $service->deleteFile($this->project->documents()->findOrFail($id), auth()->user());
        $this->refreshProject();
        Flux::toast('Dokument je obrisan.');
    }

    public function archiveReference(int $id, DocumentService $service): void
    {
        $reference = $this->project->documentReferences()->findOrFail($id);
        $service->archiveReference($reference, auth()->user(), ! $reference->is_archived);
        $this->refreshProject();
    }

    public function editReference(int $id): void
    {
        $reference = $this->project->documentReferences()->findOrFail($id);
        $this->resetValidation();
        $this->editingReferenceId = $id;
        $this->referenceForm = [
            'title' => $reference->title, 'url' => $reference->url ?? '',
            'source_app' => $reference->source_app ?? '', 'document_type' => $reference->document_type ?? '',
            'protocol_number' => $reference->protocol_number ?? '', 'document_date' => $reference->document_date?->format('Y-m-d') ?? '',
            'description' => $reference->description ?? '', 'direction' => $reference->direction->value,
        ];
    }

    public function saveReference(DocumentService $service): void
    {
        $reference = $this->project->documentReferences()->findOrFail($this->editingReferenceId);
        $this->withErrorPrefix('referenceForm.', fn () => $service->updateReference($reference, auth()->user(), $this->nullIfEmpty($this->referenceForm)));
        $this->reset('editingReferenceId', 'referenceForm');
        $this->refreshProject();
        Flux::toast('Dokument je sačuvan.', variant: 'success');
    }

    // -------------------------------------------------------------- Pomoćno

    public function with(): array
    {
        return [
            'users' => User::orderBy('name')->get(['id', 'name']),
            'institutions' => Institution::orderBy('name')->get(['id', 'name']),
            'caseTypes' => CaseType::where('is_active', true)->orderBy('name')->get(),
            'caseStatuses' => CaseStatus::where('is_active', true)->orderBy('position')->get(),
            'templates' => $this->showCaseModal ? CaseTemplate::where('is_active', true)->withCount('items')->orderBy('name')->get() : collect(),
        ];
    }

    private function findCase(int $caseId): AdministrativeCase
    {
        return $this->project->administrativeCases()->findOrFail($caseId);
    }

    private function findItem(?int $itemId): AdministrativeCaseItem
    {
        $item = AdministrativeCaseItem::with(['administrativeCase.project', 'responsible'])->findOrFail($itemId);
        abort_unless($item->administrativeCase->project_id === $this->project->id && $item->is_active, 404);

        return $item;
    }

    /**
     * Servisi validiraju s "golim" ključevima (npr. title); preimenujemo ih u
     * ključeve Livewire polja da bi se greška prikazala ispod pravog inputa.
     *
     * @param  array<string, string>  $map
     */
    private function withErrorPrefix(string $prefix, callable $callback, array $map = []): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $errors, string $key) => [($map[$key] ?? $prefix.str_replace('newCase.', '', $key)) => $errors])
                ->all());
        }
    }

    /** @param  array<string, mixed>  $data */
    private function nullIfEmpty(array $data): array
    {
        return array_map(fn ($value) => $value === '' ? null : $value, $data);
    }

    private function refreshProject(): void
    {
        $this->project = $this->project->fresh()->load([
            'category', 'responsible',
            'administrativeCases.status', 'administrativeCases.institution', 'administrativeCases.responsible',
            'administrativeCases.items' => fn ($query) => $query->where('is_active', true),
            'administrativeCases.items.responsible', 'administrativeCases.items.institution',
            'administrativeCases.items.documentReferences', 'administrativeCases.items.linkedDocumentReferences',
            'documents' => fn ($query) => $query->latest(),
            'documents.uploader', 'documents.administrativeCase', 'documents.previousVersions',
            'documentReferences' => fn ($query) => $query->latest(),
            'documentReferences.administrativeCase',
            'activities' => fn ($query) => $query->latest()->latest('id')->limit(50),
            'activities.user',
        ]);
        unset($this->cases, $this->stats);
    }
};
?>

@php($canUpdateProject = auth()->user()->can('update', $project))
@php($canCreateCase = $canUpdateProject && auth()->user()->can('create', App\Models\AdministrativeCase::class))
@php($progress = $this->stats['total'] > 0 ? (int) round($this->stats['done'] / $this->stats['total'] * 100) : null)

<div class="mx-auto flex w-full max-w-5xl flex-col gap-5">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projekti</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- ZAGLAVLJE PROJEKTA --}}
    <header class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <flux:heading size="xl">{{ $project->name }}</flux:heading>
                    <flux:badge :color="$project->status->color()" size="sm">{{ $project->status->label() }}</flux:badge>
                </div>
                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm text-zinc-500">
                    <span>{{ $project->code }}</span>
                    <span>· {{ $project->category->name }}</span>
                    @if($project->location)<span>· {{ $project->location }}</span>@endif
                    <span>· {{ $project->responsible->name }}</span>
                    @if($project->planned_end_date)<span>· završetak {{ $project->planned_end_date->format('d.m.Y.') }}</span>@endif
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @if($canUpdateProject)
                    <select wire:change="changeProjectStatus($event.target.value)" wire:key="project-status-{{ $project->status->value }}-{{ (int) $showCompleteModal }}" aria-label="Status projekta" class="rounded-lg border-zinc-200 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        @foreach(ProjectStatus::cases() as $statusOption)
                            <option value="{{ $statusOption->value }}" @selected($project->status === $statusOption)>{{ $statusOption->label() }}</option>
                        @endforeach
                    </select>
                @endif
                <flux:dropdown align="end">
                    <flux:button size="sm" icon="ellipsis-horizontal" aria-label="Više opcija" />
                    <flux:menu>
                        @if($canUpdateProject)<flux:menu.item icon="pencil-square" :href="route('projects.edit', $project)" wire:navigate>Uredi projekat</flux:menu.item>@endif
                        <flux:menu.item icon="arrow-down-tray" :href="route('projects.report.pdf', $project)">Izvještaj (PDF)</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </div>

        <div class="mt-4 flex items-center gap-3">
            <div class="h-2 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"><div class="h-full rounded-full bg-blue-600 transition-all" style="width: {{ $progress ?? 0 }}%"></div></div>
            <span class="shrink-0 text-sm text-zinc-500">
                @if($progress === null) Nema zadataka @else <strong class="text-zinc-900 dark:text-white">{{ $this->stats['done'] }}/{{ $this->stats['total'] }}</strong> zadataka završeno @endif
                @if($this->stats['overdue'] > 0) · <span class="font-medium text-red-600">{{ $this->stats['overdue'] }} kasni</span>@endif
            </span>
        </div>
    </header>

    {{-- TABOVI --}}
    <nav class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800" aria-label="Sadržaj projekta">
        @foreach([
            'predmeti' => 'Predmeti i zadaci ('.$project->administrativeCases->where('is_archived', false)->count().')',
            'dokumenti' => 'Dokumenti ('.($project->documents->where('is_archived', false)->count() + $project->documentReferences->where('is_archived', false)->count()).')',
            'istorija' => 'Istorija',
        ] as $tabKey => $tabLabel)
            <button type="button" wire:click="$set('tab', '{{ $tabKey }}')" @class([
                '-mb-px border-b-2 px-3 py-2 text-sm',
                'border-blue-600 font-medium text-zinc-900 dark:text-white' => $tab === $tabKey,
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tab !== $tabKey,
            ])>{{ $tabLabel }}</button>
        @endforeach
    </nav>

    {{-- ===================================================== PREDMETI I ZADACI --}}
    @if($tab === 'predmeti')
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap gap-1">
                @foreach(['open' => 'Otvoreni', 'overdue' => 'Kasne ('.$this->stats['overdue'].')', 'mine' => 'Moji ('.$this->stats['mine'].')', 'all' => 'Svi'] as $filterKey => $filterLabel)
                    <button type="button" wire:click="$set('filter', '{{ $filterKey }}')" @class([
                        'rounded-lg px-3 py-1.5 text-sm',
                        'bg-zinc-900 font-medium text-white dark:bg-white dark:text-zinc-900' => $filter === $filterKey,
                        'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $filter !== $filterKey,
                    ])>{{ $filterLabel }}</button>
                @endforeach
            </div>
            <div class="flex items-center gap-2">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Pretraži…" size="sm" class="sm:max-w-52" />
                @if($canCreateCase)
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openCaseModal">Novi predmet</flux:button>
                @endif
            </div>
        </div>

        <div class="flex flex-col gap-3">
            @forelse($this->cases as $case)
                @php($items = $this->visibleItems($case))
                @php($caseItems = $case->items->reject(fn ($item) => $item->status === CaseItemStatus::Cancelled))
                @php($isCollapsed = $collapsed[$case->id] ?? false)
                @php($canEditCase = auth()->user()->can('update', $case))
                <section id="predmet-{{ $case->id }}" wire:key="case-{{ $case->id }}" class="scroll-mt-4 rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                    {{-- Zaglavlje predmeta --}}
                    <div class="flex items-start gap-3 p-4">
                        <button type="button" wire:click="toggleCase({{ $case->id }})" class="mt-0.5 text-zinc-400 hover:text-zinc-700" aria-label="{{ $isCollapsed ? 'Prikaži zadatke' : 'Sakrij zadatke' }}">
                            <flux:icon :name="$isCollapsed ? 'chevron-right' : 'chevron-down'" class="size-5" />
                        </button>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="toggleCase({{ $case->id }})" class="text-left font-semibold">{{ $case->name }}</button>
                                <flux:badge :color="$case->status->color" size="sm">{{ $case->status->name }}</flux:badge>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500">
                                <span>{{ $caseItems->where('status', CaseItemStatus::Completed)->count() }}/{{ $caseItems->count() }} zadataka</span>
                                @if($case->institution)<span>· {{ $case->institution->name }}</span>@endif
                                <span>· {{ $case->responsible->name }}</span>
                                @if($case->response_due_at)
                                    <span @class(['font-medium text-red-600' => $case->closed_at === null && $case->response_due_at->isPast()])>· rok odgovora {{ $case->response_due_at->format('d.m.Y.') }}</span>
                                @endif
                            </div>
                        </div>
                        @if($canEditCase)
                            <flux:dropdown align="end">
                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Opcije predmeta" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="openCaseModal({{ $case->id }})">Uredi predmet</flux:menu.item>
                                    <flux:menu.item icon="arrow-up" wire:click="moveCase({{ $case->id }}, 'up')">Pomjeri gore</flux:menu.item>
                                    <flux:menu.item icon="arrow-down" wire:click="moveCase({{ $case->id }}, 'down')">Pomjeri dolje</flux:menu.item>
                                    @can('create', App\Models\AdministrativeCase::class)
                                        <flux:menu.item icon="document-duplicate" wire:click="duplicateCase({{ $case->id }})" wire:confirm="Kopirati predmet sa svim zadacima?">Dupliraj</flux:menu.item>
                                    @endcan
                                    <flux:menu.separator />
                                    @if($case->is_archived)
                                        <flux:menu.item icon="archive-box-arrow-down" wire:click="archiveCase({{ $case->id }})">Vrati iz arhive</flux:menu.item>
                                    @else
                                        <flux:menu.item icon="archive-box" wire:click="archiveCase({{ $case->id }})" wire:confirm="Arhivirati predmet? Podaci i istorija ostaju sačuvani.">Arhiviraj</flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        @endif
                    </div>

                    {{-- Zadaci predmeta --}}
                    @unless($isCollapsed)
                        <div class="border-t border-zinc-100 dark:border-zinc-800">
                            @forelse($items as $item)
                                {{-- Isto pravilo kao AdministrativeCaseItemPolicy, bez dodatnog upita po redu --}}
                                @php($canEditItem = $canEditCase || ($item->responsible_user_id === auth()->id() && auth()->user()->role !== App\Enums\UserRole::Viewer))
                                @php($isDone = $item->status === CaseItemStatus::Completed)
                                <div wire:key="item-{{ $item->id }}" @class(['group flex items-center gap-3 border-b border-zinc-100 px-4 py-2.5 last:border-0 dark:border-zinc-800', 'bg-blue-50/60 dark:bg-blue-950/20' => false])>
                                    <button type="button" @if($canEditItem) wire:click="toggleComplete({{ $item->id }})" @else disabled @endif
                                        @class(['flex size-5 shrink-0 items-center justify-center rounded-full border-2', 'border-emerald-500 bg-emerald-500 text-white' => $isDone, 'border-zinc-300 hover:border-emerald-500 dark:border-zinc-600' => ! $isDone])
                                        title="{{ $isDone ? 'Ponovo otvori' : 'Označi završenim' }}">
                                        @if($isDone)<flux:icon.check variant="micro" class="size-3.5" />@endif
                                    </button>
                                    <button type="button" x-on:click="$dispatch('open-task', { id: {{ $item->id }} })" class="min-w-0 flex-1 text-left">
                                        <div @class(['truncate text-sm', 'text-zinc-400 line-through' => $isDone, 'font-medium' => ! $isDone])>{{ $item->name }}</div>
                                        <div class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-zinc-500">
                                            <span>{{ $item->responsible?->name ?? 'Nije dodijeljeno' }}</span>
                                            @if($item->due_date)
                                                <span @class(['font-medium text-red-600' => $item->isOverdue(), 'text-amber-600' => ! $item->isOverdue() && $item->isDueSoon()])>· rok {{ $item->due_date->format('d.m.Y.') }}</span>
                                            @endif
                                            @if($item->documentReferences->isNotEmpty() || $item->linkedDocumentReferences->isNotEmpty())<span>· 📎 {{ $item->documentReferences->merge($item->linkedDocumentReferences)->unique('id')->count() }}</span>@endif
                                        </div>
                                    </button>
                                    @if($canEditItem)
                                        <select wire:change="changeItemStatus({{ $item->id }}, $event.target.value)" wire:key="item-status-{{ $item->id }}-{{ $item->status->value }}" aria-label="Status zadatka" class="hidden rounded-md border-zinc-200 py-1 text-xs sm:block dark:border-zinc-700 dark:bg-zinc-800">
                                            @foreach(CaseItemStatus::cases() as $statusOption)<option value="{{ $statusOption->value }}" @selected($item->status === $statusOption)>{{ $statusOption->label() }}</option>@endforeach
                                        </select>
                                    @else
                                        <flux:badge :color="$item->status->color()" size="sm">{{ $item->status->label() }}</flux:badge>
                                    @endif
                                </div>
                            @empty
                                <div class="px-4 py-5 text-center text-sm text-zinc-500">
                                    {{ $case->items->isEmpty() ? 'Predmet još nema zadataka.' : 'Nema zadataka za odabrani filter.' }}
                                </div>
                            @endforelse

                            @if($canEditCase && ! $case->is_archived)
                                <form wire:submit="addTask({{ $case->id }})" class="flex flex-col gap-2 border-t border-zinc-100 bg-zinc-50/60 p-3 sm:flex-row sm:items-start dark:border-zinc-800 dark:bg-zinc-950/30">
                                    <div class="flex-1">
                                        <flux:input wire:model="newTask.{{ $case->id }}.name" size="sm" placeholder="+ Novi zadatak (npr. Saglasnost EPBiH)" />
                                        <flux:error name="newTask.{{ $case->id }}.name" />
                                    </div>
                                    <flux:select wire:model="newTask.{{ $case->id }}.responsible_user_id" size="sm" class="sm:max-w-44" aria-label="Odgovorna osoba">
                                        <option value="">Odgovorna osoba</option>
                                        @foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach
                                    </flux:select>
                                    <flux:input wire:model="newTask.{{ $case->id }}.due_date" type="date" size="sm" class="sm:max-w-40" aria-label="Rok" />
                                    <flux:button type="submit" size="sm">Dodaj</flux:button>
                                </form>
                            @endif
                        </div>
                    @endunless
                </section>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                    <flux:heading>{{ $search !== '' || $filter !== 'open' ? 'Ništa ne odgovara filteru' : ($showArchived ? 'Nema arhiviranih predmeta' : 'Projekat još nema predmeta') }}</flux:heading>
                    <flux:text class="mt-1">Predmet je npr. "Urbanistička saglasnost" ili "Građevinska dozvola", a zadaci su koraci koje treba riješiti u okviru njega.</flux:text>
                    @if($canCreateCase && ! $showArchived)
                        <flux:button class="mt-4" variant="primary" icon="plus" wire:click="openCaseModal">Dodaj prvi predmet</flux:button>
                    @endif
                </div>
            @endforelse
        </div>

        <div class="text-center">
            <button type="button" wire:click="$toggle('showArchived')" class="text-sm text-zinc-500 hover:text-blue-600">{{ $showArchived ? '← Nazad na aktivne predmete' : 'Prikaži arhivirane predmete' }}</button>
        </div>
    @endif

    {{-- ============================================================ DOKUMENTI --}}
    @if($tab === 'dokumenti')
        @if($canUpdateProject)
            <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading>Dodaj dokument</flux:heading>
                    <div class="flex gap-1">
                        @foreach(['upload' => 'Datoteka', 'link' => 'Vanjski link', 'protocol' => 'Iz protokola'] as $modeKey => $modeLabel)
                            <flux:button size="sm" :variant="$docMode === $modeKey ? 'primary' : 'ghost'" wire:click="$set('docMode', '{{ $modeKey }}')">{{ $modeLabel }}</flux:button>
                        @endforeach
                    </div>
                </div>
                @php($caseOptions = $project->administrativeCases->where('is_archived', false))
                @if($docMode === 'upload')
                    <form wire:submit="uploadDocument" class="mt-3 grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="document" type="file" label="Datoteka ({{ config('projectflow.documents.allowed_labels') }}, max {{ number_format(config('projectflow.documents.max_size') / 1024, 0) }} MB)" />
                        <flux:input wire:model="documentName" label="Naziv (opciono)" />
                        <flux:input wire:model="documentType" label="Vrsta dokumenta" placeholder="npr. Saglasnost, Rješenje" />
                        <flux:select wire:model="documentCaseId" label="Predmet"><option value="">Cijeli projekat</option>@foreach($caseOptions as $caseOption)<option value="{{ $caseOption->id }}">{{ $caseOption->name }}</option>@endforeach</flux:select>
                        <flux:textarea wire:model="documentDescription" label="Napomena" rows="2" class="sm:col-span-2" />
                        <div class="sm:col-span-2"><flux:button type="submit" variant="primary" size="sm">Dodaj dokument</flux:button></div>
                    </form>
                @elseif($docMode === 'link')
                    <form wire:submit="addLink" class="mt-3 grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="linkForm.title" label="Naziv dokumenta" />
                        <flux:input wire:model="linkForm.url" label="URL (http/https)" />
                        <flux:select wire:model="linkForm.direction" label="Smjer"><option value="">Odaberite</option>@foreach(DocumentDirection::cases() as $direction)<option value="{{ $direction->value }}">{{ $direction->label() }}</option>@endforeach</flux:select>
                        <flux:select wire:model="linkForm.administrative_case_id" label="Predmet"><option value="">Cijeli projekat</option>@foreach($caseOptions as $caseOption)<option value="{{ $caseOption->id }}">{{ $caseOption->name }}</option>@endforeach</flux:select>
                        <flux:input wire:model="linkForm.protocol_number" label="Broj protokola (opciono)" />
                        <flux:input wire:model="linkForm.document_date" type="date" label="Datum dokumenta" />
                        <div class="sm:col-span-2"><flux:button type="submit" variant="primary" size="sm">Dodaj link</flux:button></div>
                    </form>
                @else
                    <form wire:submit="addProtocol" class="mt-3 grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="protocolForm.title" label="Naziv dokumenta" />
                        <flux:input wire:model="protocolForm.external_document_id" label="ID dokumenta u protokolu" />
                        <flux:input wire:model="protocolForm.protocol_number" label="Broj protokola" />
                        <flux:input wire:model="protocolForm.document_date" type="date" label="Datum dokumenta" />
                        <flux:select wire:model="protocolForm.direction" label="Smjer"><option value="">Odaberite</option>@foreach(DocumentDirection::cases() as $direction)<option value="{{ $direction->value }}">{{ $direction->label() }}</option>@endforeach</flux:select>
                        <flux:select wire:model="protocolForm.administrative_case_id" label="Predmet"><option value="">Cijeli projekat</option>@foreach($caseOptions as $caseOption)<option value="{{ $caseOption->id }}">{{ $caseOption->name }}</option>@endforeach</flux:select>
                        <div class="sm:col-span-2"><flux:button type="submit" variant="primary" size="sm">Poveži s protokolom</flux:button></div>
                    </form>
                @endif
            </section>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:input wire:model.live.debounce.300ms="documentSearch" icon="magnifying-glass" placeholder="Pretraži dokumente…" size="sm" class="max-w-64" />
            <button type="button" wire:click="$toggle('showArchivedDocuments')" class="text-sm text-zinc-500 hover:text-blue-600">{{ $showArchivedDocuments ? '← Aktivni dokumenti' : 'Prikaži arhivirane' }}</button>
        </div>

        @php($needle = mb_strtolower(trim($documentSearch)))
        @php($files = $project->documents->where('is_archived', $showArchivedDocuments)->filter(fn ($doc) => $needle === '' || str_contains(mb_strtolower($doc->name.' '.$doc->document_type), $needle)))
        @php($references = $project->documentReferences->where('is_archived', $showArchivedDocuments)->filter(fn ($doc) => $needle === '' || str_contains(mb_strtolower($doc->title.' '.$doc->protocol_number.' '.$doc->external_document_id), $needle)))

        <section class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
            @foreach($files as $doc)
                <div class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center" wire:key="doc-{{ $doc->id }}">
                    <flux:icon.document class="hidden size-5 shrink-0 text-zinc-400 sm:block" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('projects.documents.download', [$project, $doc]) }}" class="text-sm font-medium hover:text-blue-600">{{ $doc->name }}</a>
                            @if($doc->version > 1)<flux:badge color="zinc" size="sm">v{{ $doc->version }}</flux:badge>@endif
                            @if($doc->document_type)<flux:badge color="blue" size="sm">{{ $doc->document_type }}</flux:badge>@endif
                        </div>
                        <div class="text-xs text-zinc-500">{{ $doc->administrativeCase?->name ?? 'Cijeli projekat' }} · {{ number_format($doc->size / 1024, 0) }} KB · {{ $doc->created_at->format('d.m.Y.') }} · {{ $doc->uploader?->name }}{{ $doc->description ? ' · '.$doc->description : '' }}</div>
                    </div>
                    @if($canUpdateProject && $doc->replaced_by_id === null)
                        <div class="flex gap-1">
                            @unless($doc->is_archived)<flux:button size="xs" variant="ghost" wire:click="$set('replaceDocumentId', {{ $doc->id }})">Nova verzija</flux:button>@endunless
                            <flux:button size="xs" variant="ghost" wire:click="archiveDocument({{ $doc->id }})">{{ $doc->is_archived ? 'Vrati' : 'Arhiviraj' }}</flux:button>
                            @if($doc->previousVersions->isEmpty())<flux:button size="xs" variant="ghost" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="Trajno obrisati dokument?">Obriši</flux:button>@endif
                        </div>
                    @endif
                </div>
            @endforeach
            @foreach($references as $doc)
                <div class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center" wire:key="ref-{{ $doc->id }}">
                    <flux:icon :name="$doc->url ? 'link' : 'inbox-stack'" class="hidden size-5 shrink-0 text-zinc-400 sm:block" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            @if($doc->url)<a href="{{ $doc->url }}" target="_blank" rel="noopener noreferrer" class="text-sm font-medium hover:text-blue-600">{{ $doc->title }} ↗</a>
                            @else<span class="text-sm font-medium">{{ $doc->title }}</span>@endif
                            @if($doc->protocol_number)<flux:badge color="zinc" size="sm">{{ $doc->protocol_number }}</flux:badge>@endif
                            <flux:badge :color="$doc->direction->color()" size="sm">{{ $doc->direction->label() }}</flux:badge>
                        </div>
                        <div class="text-xs text-zinc-500">{{ $doc->administrativeCase?->name ?? 'Cijeli projekat' }} · {{ $doc->external_system }} · {{ $doc->document_date?->format('d.m.Y.') ?? 'bez datuma' }}{{ $doc->description ? ' · '.$doc->description : '' }}</div>
                    </div>
                    @if($canUpdateProject)
                        <div class="flex gap-1">
                            <flux:button size="xs" variant="ghost" wire:click="editReference({{ $doc->id }})">Uredi</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="archiveReference({{ $doc->id }})">{{ $doc->is_archived ? 'Vrati' : 'Arhiviraj' }}</flux:button>
                        </div>
                    @endif
                </div>
            @endforeach
            @if($files->isEmpty() && $references->isEmpty())
                <div class="px-6 py-12 text-center text-sm text-zinc-500">{{ $needle !== '' ? 'Nijedan dokument ne odgovara pretrazi.' : 'Nema dokumenata.' }}</div>
            @endif
        </section>
    @endif

    {{-- ============================================================= ISTORIJA --}}
    @if($tab === 'istorija')
        <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <flux:text class="text-sm">Posljednjih 50 promjena na projektu.</flux:text>
            <div class="mt-3 space-y-3">
                @forelse($project->activities as $activity)
                    <div class="flex gap-3 text-sm" wire:key="activity-{{ $activity->id }}">
                        <time class="w-28 shrink-0 text-xs text-zinc-400" datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->format('d.m.Y. H:i') }}</time>
                        <div class="min-w-0">
                            <span class="font-medium">{{ $activity->title }}</span>
                            <span class="text-zinc-500">— {{ $activity->user?->name ?? 'Sistem' }}</span>
                            @if($activity->description)<div class="text-zinc-500">{{ $activity->description }}</div>@endif
                        </div>
                    </div>
                @empty
                    <flux:text class="text-sm">Nema evidentiranih aktivnosti.</flux:text>
                @endforelse
            </div>
        </section>
    @endif

    {{-- ==================================================== MODAL: PREDMET --}}
    @if($showCaseModal)
        <div class="fixed inset-0 z-40 bg-black/20" wire:click="$set('showCaseModal', false)"></div>
        <div class="fixed inset-x-4 top-10 z-50 mx-auto max-h-[85vh] max-w-lg overflow-y-auto rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900" role="dialog" aria-label="Predmet">
            <flux:heading size="lg">{{ $editingCaseId ? 'Uredi predmet' : 'Novi predmet' }}</flux:heading>
            @if(! $editingCaseId && $templates->isNotEmpty())
                <div class="mt-3 flex gap-1">
                    <flux:button size="sm" :variant="$caseMode === 'manual' ? 'primary' : 'ghost'" wire:click="$set('caseMode', 'manual')">Prazan predmet</flux:button>
                    <flux:button size="sm" :variant="$caseMode === 'template' ? 'primary' : 'ghost'" wire:click="$set('caseMode', 'template')">Iz šablona</flux:button>
                </div>
            @endif
            <form wire:submit="saveCase" class="mt-4 space-y-3">
                @if(! $editingCaseId && $caseMode === 'template')
                    <flux:select wire:model="templateId" label="Šablon (zadaci se kreiraju automatski)"><option value="">Odaberite šablon</option>@foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->name }} ({{ $template->items_count }} zadataka)</option>@endforeach</flux:select>
                @endif
                <flux:input wire:model="caseForm.name" label="Naziv predmeta" placeholder="npr. Urbanistička saglasnost" />
                <div class="grid grid-cols-2 gap-3">
                    <flux:select wire:model="caseForm.case_type_id" label="Vrsta"><option value="">{{ $caseMode === 'template' && ! $editingCaseId ? 'Iz šablona' : 'Odaberite' }}</option>@foreach($caseTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="caseForm.case_status_id" label="Status">@foreach($caseStatuses as $caseStatus)<option value="{{ $caseStatus->id }}">{{ $caseStatus->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="caseForm.institution_id" label="Institucija"><option value="">Bez institucije</option>@foreach($institutions as $institution)<option value="{{ $institution->id }}">{{ $institution->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="caseForm.responsible_user_id" label="Odgovorna osoba">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</flux:select>
                    <flux:input wire:model="caseForm.started_at" type="date" label="Datum pokretanja" />
                    <flux:input wire:model="caseForm.response_due_at" type="date" label="Rok za odgovor" />
                </div>
                <flux:input wire:model="caseForm.reminder_days_before" type="number" min="0" max="365" label="Podsjeti koliko dana prije roka" />
                <flux:textarea wire:model="caseForm.description" label="Opis" rows="2" />
                <div class="flex justify-end gap-2 pt-2">
                    <flux:button type="button" variant="ghost" wire:click="$set('showCaseModal', false)">Odustani</flux:button>
                    <flux:button type="submit" variant="primary">{{ $editingCaseId ? 'Sačuvaj' : 'Kreiraj predmet' }}</flux:button>
                </div>
            </form>
        </div>
    @endif

    {{-- ======================================== MODAL: ZAVRŠAVANJE PROJEKTA --}}
    @if($showCompleteModal)
        <div class="fixed inset-0 z-40 bg-black/20" wire:click="$set('showCompleteModal', false)"></div>
        <div class="fixed inset-x-4 top-10 z-50 mx-auto max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900" role="dialog" aria-label="Završavanje projekta">
            <flux:heading size="lg">Završavanje projekta</flux:heading>
            @if($blockersForCompletion !== [])
                <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                    <strong>Obavezni zadaci nisu završeni:</strong>
                    <ul class="mt-1 list-disc pl-5">@foreach($blockersForCompletion as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
                    Projekat možete završiti uz obrazloženje.
                </div>
            @endif
            <form wire:submit="completeProject" class="mt-4 space-y-3">
                <flux:textarea wire:model="completionNote" label="Obrazloženje{{ $blockersForCompletion !== [] ? '' : ' (opciono)' }}" rows="3" />
                <flux:error name="completion_note" />
                <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('showCompleteModal', false)">Odustani</flux:button><flux:button type="submit" variant="primary">Završi projekat</flux:button></div>
            </form>
        </div>
    @endif

    {{-- ==================================== MODAL: NOVA VERZIJA DOKUMENTA --}}
    @if($replaceDocumentId)
        <div class="fixed inset-0 z-40 bg-black/20" wire:click="$set('replaceDocumentId', null)"></div>
        <div class="fixed inset-x-4 top-10 z-50 mx-auto max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900" role="dialog" aria-label="Nova verzija dokumenta">
            <flux:heading size="lg">Nova verzija dokumenta</flux:heading>
            <flux:text class="mt-1 text-sm">Prethodna verzija ostaje sačuvana u arhivi.</flux:text>
            <form wire:submit="replaceDocumentVersion" class="mt-4 space-y-3">
                <flux:input wire:model="versionFile" type="file" label="Nova datoteka" />
                <div class="flex justify-end gap-2"><flux:button type="button" variant="ghost" wire:click="$set('replaceDocumentId', null)">Odustani</flux:button><flux:button type="submit" variant="primary">Zamijeni</flux:button></div>
            </form>
        </div>
    @endif

    {{-- ============================================ MODAL: UREĐIVANJE REFERENCE --}}
    @if($editingReferenceId)
        <div class="fixed inset-0 z-40 bg-black/20" wire:click="$set('editingReferenceId', null)"></div>
        <div class="fixed inset-x-4 top-10 z-50 mx-auto max-w-lg rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900" role="dialog" aria-label="Uređivanje dokumenta">
            <flux:heading size="lg">Uređivanje dokumenta</flux:heading>
            <form wire:submit="saveReference" class="mt-4 grid gap-3 sm:grid-cols-2">
                <flux:input wire:model="referenceForm.title" label="Naziv" />
                <flux:input wire:model="referenceForm.url" label="URL" />
                <flux:input wire:model="referenceForm.protocol_number" label="Broj protokola" />
                <flux:input wire:model="referenceForm.document_date" type="date" label="Datum dokumenta" />
                <flux:input wire:model="referenceForm.document_type" label="Vrsta dokumenta" />
                <flux:select wire:model="referenceForm.direction" label="Smjer">@foreach(DocumentDirection::cases() as $direction)<option value="{{ $direction->value }}">{{ $direction->label() }}</option>@endforeach</flux:select>
                <flux:textarea wire:model="referenceForm.description" label="Opis" rows="2" class="sm:col-span-2" />
                <div class="flex justify-end gap-2 sm:col-span-2"><flux:button type="button" variant="ghost" wire:click="$set('editingReferenceId', null)">Odustani</flux:button><flux:button type="submit" variant="primary">Sačuvaj</flux:button></div>
            </form>
        </div>
    @endif
</div>
