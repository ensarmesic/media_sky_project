<?php

use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCaseItem;
use App\Models\ChecklistItem;
use App\Models\Institution;
use App\Services\AdministrativeCaseItemService;
use App\Services\DocumentService;
use App\Support\RichText;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Detalj stavke. Na stranicama se otvara kao bočni panel (event "open-task",
 * URL parametar ?stavka=ID), a na /stavke/{id} se prikazuje kao cijela stranica.
 */
new class extends Component {
    use WithFileUploads;

    #[Url(as: 'stavka', except: null)]
    public ?int $openId = null;

    /** Postavljeno samo na stranici /stavke/{id}. */
    #[Locked]
    public ?int $pageTaskId = null;

    public array $form = [];

    public string $newChecklistItem = '';

    // "Evidentiraj odgovor" (datum + broj protokola + dopis u jednom koraku)
    public bool $showResponseForm = false;
    public array $response = ['date' => '', 'protocol_number' => '', 'outcome' => 'completed'];
    public $responseFile;

    public function mount(?int $pageTaskId = null): void
    {
        $this->pageTaskId = $pageTaskId;
        if ($this->task() !== null) {
            $this->fillForm();
        } else {
            $this->openId = null;
        }
    }

    #[On('open-task')]
    public function open(int $id, ?string $section = null): void
    {
        $this->openId = $id;
        unset($this->task);
        if ($this->task() === null) {
            $this->openId = null;
            Flux::toast('Stavka nije dostupna.', variant: 'danger');

            return;
        }
        $this->resetValidation();
        $this->reset('newChecklistItem', 'showResponseForm', 'response', 'responseFile');
        $this->fillForm();
        if ($section !== null) {
            $this->js("setTimeout(() => document.getElementById('stavka-sekcija-{$section}')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150)");
        }
    }

    public function close(): void
    {
        $this->openId = null;
        unset($this->task);
    }

    #[Computed]
    public function task(): ?AdministrativeCaseItem
    {
        $id = $this->pageTaskId ?? $this->openId;
        if ($id === null) {
            return null;
        }

        $task = AdministrativeCaseItem::with(['administrativeCase.project', 'responsible', 'institution', 'checklistItems'])
            ->where('is_active', true)
            ->find($id);

        return $task !== null && auth()->user()->can('view', $task) ? $task : null;
    }

    #[Computed]
    public function canEdit(): bool
    {
        return $this->task() !== null && auth()->user()->can('update', $this->task());
    }

    /** Članovi projekta (odgovorna osoba se bira među njima). */
    #[Computed]
    public function users(): Collection
    {
        $project = $this->task()->administrativeCase->project;

        return $project->members()->orderBy('name')->get(['users.id', 'users.name'])
            ->when($this->task()->responsible && ! $project->members()->whereKey($this->task()->responsible_user_id)->exists(), fn ($users) => $users->push($this->task()->responsible));
    }

    #[Computed]
    public function institutions(): Collection
    {
        return Institution::orderBy('name')->get(['id', 'name']);
    }

    /** Svako polje se snima odmah nakon izmjene. */
    public function updatedForm(mixed $value, string $key): void
    {
        $task = $this->editableTask();
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::enum(CaseItemStatus::class)],
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'recipient' => ['nullable', 'string', 'max:255'],
            'responsible_user_id' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'submitted_at' => ['nullable', 'date'],
            'response_at' => ['nullable', 'date'],
        ];
        abort_unless(isset($rules[$key]), 422);

        $this->validate(["form.$key" => $rules[$key]], attributes: ["form.$key" => match ($key) {
            'name' => 'naziv', 'description' => 'opis', 'institution_id' => 'institucija', 'recipient' => 'primalac',
            'responsible_user_id' => 'odgovorna osoba', 'due_date' => 'rok', 'submitted_at' => 'datum predaje', 'response_at' => 'datum odgovora', default => $key,
        }]);

        app(AdministrativeCaseItemService::class)->update($task, [$key => $value === '' ? null : (is_string($value) && $key !== 'description' ? trim($value) : $value)], auth()->user());
        $this->saved();
    }

    // ------------------------------------------------------------ Tok rada

    public function markSubmitted(AdministrativeCaseItemService $service): void
    {
        $this->setStatus($service, CaseItemStatus::Submitted, 'Stavka je označena kao predata.');
    }

    public function markSupplementRequired(AdministrativeCaseItemService $service): void
    {
        $this->setStatus($service, CaseItemStatus::SupplementRequired, 'Stavka traži dopunu.');
    }

    public function markCompleted(AdministrativeCaseItemService $service): void
    {
        $this->setStatus($service, CaseItemStatus::Completed, 'Stavka je završena.');
    }

    public function openResponseForm(): void
    {
        $this->editableTask();
        $this->resetValidation();
        $this->response = ['date' => local_today()->toDateString(), 'protocol_number' => '', 'outcome' => CaseItemStatus::Completed->value];
        $this->responseFile = null;
        $this->showResponseForm = true;
    }

    /** Evidentiranje odgovora institucije: datum, broj protokola i (opciono) dopis — u jednom koraku. */
    public function recordResponse(AdministrativeCaseItemService $items, DocumentService $documents): void
    {
        $task = $this->editableTask();
        $this->validate([
            'response.date' => ['required', 'date'],
            'response.protocol_number' => ['nullable', 'string', 'max:100'],
            'response.outcome' => ['required', Rule::in([CaseItemStatus::Completed->value, CaseItemStatus::SupplementRequired->value, CaseItemStatus::WaitingResponse->value])],
            'responseFile' => ['nullable', ...array_slice(DocumentService::fileRules(), 1)],
        ], DocumentService::fileMessages('responseFile'), ['response.date' => 'datum odgovora', 'response.protocol_number' => 'broj protokola']);

        DB::transaction(function () use ($task, $items, $documents) {
            $items->update($task, ['response_at' => $this->response['date'], 'status' => $this->response['outcome']], auth()->user());
            if ($this->responseFile !== null) {
                $documents->store($task->fresh(), auth()->user(), $this->responseFile, [
                    'name' => 'Odgovor – '.$task->name,
                    'direction' => 'incoming',
                    'protocol_number' => $this->response['protocol_number'] ?: null,
                    'document_date' => $this->response['date'],
                ]);
            }
        });

        $this->reset('showResponseForm', 'response', 'responseFile');
        $this->fillForm();
        $this->saved('Odgovor je evidentiran.');
    }

    public function duplicate(AdministrativeCaseItemService $service): void
    {
        $task = $this->editableTask();
        Gate::authorize('update', $task->administrativeCase);
        $copy = $service->duplicate($task, auth()->user());
        $this->dispatch('task-updated');

        if ($this->pageTaskId !== null) {
            $this->redirectRoute('tasks.show', $copy, navigate: true);

            return;
        }
        $this->open($copy->id);
        Flux::toast('Stavka je duplicirana.', variant: 'success');
    }

    public function delete(AdministrativeCaseItemService $service): void
    {
        $task = $this->editableTask();
        Gate::authorize('update', $task->administrativeCase);
        $service->deactivate($task, auth()->user());
        $this->dispatch('task-updated');
        Flux::toast('Stavka je obrisana.');

        if ($this->pageTaskId !== null) {
            $this->redirectRoute('projects.show', $task->administrativeCase->project_id, navigate: true);

            return;
        }
        $this->close();
    }

    // ---------------------------------------------------------------- Checklist

    public function addChecklistItem(AdministrativeCaseItemService $service): void
    {
        $task = $this->editableTask();
        $this->validate(['newChecklistItem' => ['required', 'string', 'max:255']], attributes: ['newChecklistItem' => 'stavka checkliste']);
        $service->addChecklistItem($task, $this->newChecklistItem);
        $this->newChecklistItem = '';
        $this->saved(null);
    }

    public function toggleChecklistItem(int $id): void
    {
        $item = $this->checklistItem($id);
        $item->update(['is_done' => ! $item->is_done]);
        $this->saved(null);
    }

    public function deleteChecklistItem(int $id): void
    {
        $this->checklistItem($id)->delete();
        $this->saved(null);
    }

    public function sortChecklist(int|string $id, int $position, AdministrativeCaseItemService $service): void
    {
        $service->moveChecklistItem($this->checklistItem((int) $id), $position);
        $this->saved(null);
    }

    // ---------------------------------------------------------------- Pomoćno

    private function setStatus(AdministrativeCaseItemService $service, CaseItemStatus $status, string $message): void
    {
        $service->changeStatus($this->editableTask(), $status, auth()->user());
        $this->fillForm();
        $this->saved($message);
    }

    private function editableTask(): AdministrativeCaseItem
    {
        $task = $this->task();
        abort_if($task === null, 404);
        Gate::authorize('update', $task);

        return $task;
    }

    private function checklistItem(int $id): ChecklistItem
    {
        return $this->editableTask()->checklistItems()->findOrFail($id);
    }

    private function fillForm(): void
    {
        unset($this->task);
        $task = $this->task();
        $this->form = [
            'name' => $task->name,
            'description' => $task->description ?? '',
            'status' => $task->status->value,
            'institution_id' => (string) ($task->institution_id ?? ''),
            'recipient' => $task->recipient ?? '',
            'responsible_user_id' => (string) ($task->responsible_user_id ?? ''),
            'due_date' => $task->due_date?->format('Y-m-d') ?? '',
            'submitted_at' => $task->submitted_at?->format('Y-m-d') ?? '',
            'response_at' => $task->response_at?->format('Y-m-d') ?? '',
        ];
    }

    private function saved(?string $message = 'Sačuvano'): void
    {
        unset($this->task);
        if ($this->task() !== null) {
            // Automatske promjene (npr. datum predaje) prikazujemo odmah u poljima.
            $this->form['submitted_at'] = $this->task()->submitted_at?->format('Y-m-d') ?? '';
            $this->form['response_at'] = $this->task()->response_at?->format('Y-m-d') ?? '';
            $this->form['status'] = $this->task()->status->value;
        }
        $this->dispatch('task-updated');
        if ($message !== null) {
            Flux::toast($message, variant: 'success', duration: 1500);
        }
    }
}; ?>

@php($task = $this->task)
<div>
    @if($task)
        @php($canEdit = $this->canEdit)
        @php($checklist = $task->checklistItems)
        @php($isOverdue = $task->isOverdue())

        @unless($pageTaskId)
            <div class="fixed inset-0 z-40 bg-black/30" wire:click="close" aria-hidden="true"></div>
        @endunless

        <aside
            @if(! $pageTaskId) x-data x-init="$nextTick(() => $el.focus())" x-on:keydown.escape.window="if (! document.querySelector('[data-lightbox-open]')) $wire.close()" tabindex="-1" role="dialog" aria-modal="true" @endif
            aria-labelledby="task-title-{{ $task->id }}"
            @class([
                'flex flex-col bg-white focus:outline-none dark:bg-zinc-900',
                'fixed inset-y-0 right-0 z-50 w-full max-w-3xl overflow-y-auto border-l border-zinc-200 shadow-2xl dark:border-zinc-800' => ! $pageTaskId,
                'rounded-xl border border-zinc-200 dark:border-zinc-800' => $pageTaskId,
            ])>
            <span class="sr-only">Detalji stavke</span>

            {{-- Zaglavlje: breadcrumb + akcije --}}
            <div class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-zinc-200 bg-white/95 px-5 py-3 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
                <nav class="min-w-0 truncate text-xs text-zinc-500" aria-label="Putanja">
                    <a href="{{ route('projects.show', $task->administrativeCase->project) }}" wire:navigate class="hover:text-blue-600">{{ $task->administrativeCase->project->name }}</a>
                    <span class="mx-1">›</span>
                    <a href="{{ $task->administrativeCase->url() }}" wire:navigate class="hover:text-blue-600">{{ $task->administrativeCase->name }}</a>
                </nav>
                <div class="flex shrink-0 items-center gap-1">
                    @can('update', $task->administrativeCase)
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Više akcija" />
                            <flux:menu>
                                <flux:menu.item icon="document-duplicate" wire:click="duplicate">Dupliciraj</flux:menu.item>
                                @unless($pageTaskId)<flux:menu.item icon="arrow-top-right-on-square" :href="route('tasks.show', $task)" wire:navigate>Otvori kao stranicu</flux:menu.item>@endunless
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="delete" wire:confirm="Obrisati stavku? Istorija i dokumenti ostaju sačuvani.">Obriši</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endcan
                    @unless($pageTaskId)
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="close" aria-label="Zatvori" />
                    @endunless
                </div>
            </div>

            <div class="grid gap-6 p-5 md:grid-cols-[minmax(0,1fr)_15rem]">
                {{-- Glavni sadržaj --}}
                <div class="min-w-0 space-y-6">
                    <div>
                        @if($canEdit)
                            <label for="task-title-{{ $task->id }}" class="sr-only">Naziv stavke</label>
                            <input id="task-title-{{ $task->id }}" type="text" wire:model.live.blur="form.name" x-on:keydown.enter.prevent="$el.blur()"
                                class="w-full rounded-md border border-transparent bg-transparent px-1 py-0.5 text-xl font-semibold hover:border-zinc-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:hover:border-zinc-700" />
                            <flux:error name="form.name" />
                        @else
                            <h2 id="task-title-{{ $task->id }}" class="text-xl font-semibold">{{ $task->name }}</h2>
                        @endif
                        <div class="mt-2 flex flex-wrap items-center gap-2 px-1">
                            <span class="{{ $task->status->chipClass() }}">{{ $task->status->label() }}</span>
                            @if($isOverdue)<span class="text-xs font-semibold text-red-600">Kasni {{ abs($task->daysUntilDue()) }} {{ App\Services\AttentionService::days(abs($task->daysUntilDue())) }}</span>@endif
                            @if($task->daysWaiting() !== null)<span class="text-xs text-orange-600">Čeka odgovor {{ $task->daysWaiting() }} {{ App\Services\AttentionService::days($task->daysWaiting()) }}</span>@endif
                        </div>
                    </div>

                    {{-- Tok rada --}}
                    @if($canEdit)
                        <div class="flex flex-wrap gap-2" role="group" aria-label="Tok rada">
                            @if(in_array($task->status, [CaseItemStatus::NotStarted, CaseItemStatus::InPreparation, CaseItemStatus::SupplementRequired], true))
                                <flux:button size="sm" icon="arrow-right" wire:click="markSubmitted">Označi kao predato</flux:button>
                            @endif
                            @unless($task->status === CaseItemStatus::Completed)
                                <flux:button size="sm" icon="envelope" wire:click="openResponseForm">Evidentiraj odgovor</flux:button>
                                @unless($task->status === CaseItemStatus::SupplementRequired)<flux:button size="sm" icon="exclamation-circle" wire:click="markSupplementRequired">Potrebna dopuna</flux:button>@endunless
                                <flux:button size="sm" variant="primary" icon="check" wire:click="markCompleted">Završi</flux:button>
                            @endunless
                        </div>

                        @if($showResponseForm)
                            <form wire:submit="recordResponse" class="space-y-3 rounded-lg border border-blue-200 bg-blue-50/50 p-4 dark:border-blue-900 dark:bg-blue-950/20" aria-label="Evidentiraj odgovor">
                                <div class="flex items-center justify-between"><h3 class="text-sm font-semibold">Odgovor institucije</h3><button type="button" wire:click="$set('showResponseForm', false)" class="text-xs text-zinc-500 hover:text-zinc-800">Odustani</button></div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <flux:input wire:model="response.date" type="date" label="Datum odgovora" size="sm" />
                                    <flux:input wire:model="response.protocol_number" label="Broj protokola" placeholder="npr. 02-15-321/26" size="sm" />
                                </div>
                                <flux:select wire:model="response.outcome" label="Ishod" size="sm">
                                    <option value="{{ CaseItemStatus::Completed->value }}">Odobreno — stavka je završena</option>
                                    <option value="{{ CaseItemStatus::SupplementRequired->value }}">Traži se dopuna</option>
                                    <option value="{{ CaseItemStatus::WaitingResponse->value }}">Djelimičan odgovor — i dalje čeka</option>
                                </flux:select>
                                <div>
                                    <flux:input wire:model="responseFile" type="file" label="Dopis (opciono)" size="sm" />
                                    <div wire:loading wire:target="responseFile" class="mt-1 text-xs text-zinc-500">Upload…</div>
                                </div>
                                <flux:button type="submit" size="sm" variant="primary">Sačuvaj odgovor</flux:button>
                            </form>
                        @endif
                    @endif

                    {{-- Opis --}}
                    <section x-data="{ editing: false }" aria-label="Opis">
                        <div class="mb-1 flex items-center justify-between">
                            <h3 class="text-sm font-medium text-zinc-500">Opis</h3>
                            @if($canEdit)<button type="button" x-show="! editing" x-on:click="editing = true; $nextTick(() => $refs.description.focus())" class="text-xs text-blue-600 hover:underline">Uredi</button>@endif
                        </div>
                        <div x-show="! editing" @if($canEdit) x-on:dblclick="editing = true; $nextTick(() => $refs.description.focus())" @endif>
                            @if(filled($task->description))
                                <div class="rich-text text-zinc-700 dark:text-zinc-200">{{ RichText::render($task->description) }}</div>
                            @else
                                <p class="text-sm text-zinc-400">{{ $canEdit ? 'Dodajte opis (linkovi su klikabilni; podržani **bold**, `kod` i liste)…' : 'Nema opisa.' }}</p>
                            @endif
                        </div>
                        @if($canEdit)
                            <div x-show="editing" x-cloak>
                                <textarea x-ref="description" wire:model.live.blur="form.description" x-on:blur="editing = false" rows="5" aria-label="Opis stavke"
                                    class="w-full rounded-lg border border-zinc-200 bg-white p-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                            </div>
                            <flux:error name="form.description" />
                        @endif
                    </section>

                    {{-- Checklist --}}
                    <section aria-labelledby="checklist-heading-{{ $task->id }}">
                        <div class="mb-2 flex items-center justify-between">
                            <h3 id="checklist-heading-{{ $task->id }}" class="text-sm font-medium text-zinc-500">Checklist</h3>
                            @if($checklist->isNotEmpty())<span class="text-xs font-medium text-zinc-500">{{ $checklist->where('is_done', true)->count() }}/{{ $checklist->count() }}</span>@endif
                        </div>
                        @if($checklist->isNotEmpty())
                            <div class="mb-2 h-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"><div class="h-full rounded-full bg-green-500" style="width: {{ (int) round($checklist->where('is_done', true)->count() / $checklist->count() * 100) }}%"></div></div>
                        @endif
                        <ul @if($canEdit) wire:sort="sortChecklist" @endif class="space-y-1">
                            @foreach($checklist as $checklistItem)
                                <li wire:key="checklist-{{ $checklistItem->id }}" @if($canEdit) wire:sort:item="{{ $checklistItem->id }}" @endif class="group flex items-center gap-2 rounded-md px-1 py-1 hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                    @if($canEdit)<span wire:sort:handle class="cursor-grab text-zinc-300 opacity-0 group-hover:opacity-100" aria-hidden="true"><flux:icon.bars-2 variant="micro" class="size-4" /></span>@endif
                                    <input type="checkbox" id="checklist-item-{{ $checklistItem->id }}" @checked($checklistItem->is_done) @disabled(! $canEdit) wire:click="toggleChecklistItem({{ $checklistItem->id }})" class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600" />
                                    <label for="checklist-item-{{ $checklistItem->id }}" @class(['flex-1 text-sm', 'text-zinc-400 line-through' => $checklistItem->is_done])>{{ $checklistItem->title }}</label>
                                    @if($canEdit)
                                        <button type="button" wire:click="deleteChecklistItem({{ $checklistItem->id }})" class="text-zinc-300 opacity-0 hover:text-red-600 focus:opacity-100 group-hover:opacity-100" aria-label="Obriši stavku checkliste {{ $checklistItem->title }}"><flux:icon.x-mark variant="micro" class="size-4" /></button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if($canEdit)
                            <form wire:submit="addChecklistItem" class="mt-1">
                                <input type="text" wire:model="newChecklistItem" placeholder="+ Dodaj podzadatak (Enter)" aria-label="Novi podzadatak"
                                    class="w-full rounded-md border border-transparent bg-transparent px-2 py-1 text-sm placeholder:text-zinc-400 hover:border-zinc-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:hover:border-zinc-700" />
                                <flux:error name="newChecklistItem" />
                            </form>
                        @elseif($checklist->isEmpty())
                            <p class="text-sm text-zinc-400">Nema podzadataka.</p>
                        @endif
                    </section>

                    {{-- Dokumenti --}}
                    <section id="stavka-sekcija-dokumenti" class="scroll-mt-16" aria-labelledby="documents-heading-{{ $task->id }}">
                        <h3 id="documents-heading-{{ $task->id }}" class="mb-2 text-sm font-medium text-zinc-500">Dokumenti</h3>
                        <livewire:documents owner-type="item" :owner-id="$task->id" wire:key="item-documents-{{ $task->id }}" />
                    </section>

                    {{-- Linkovi, komentari i aktivnost --}}
                    @includeIf('livewire.partials.task-drawer-sections', ['task' => $task])
                </div>

                {{-- Polja uz bok --}}
                <aside class="space-y-4 md:border-l md:border-zinc-100 md:pl-5 md:dark:border-zinc-800" aria-label="Svojstva stavke">
                    <flux:select wire:model.live="form.status" label="Status" size="sm" :disabled="! $canEdit">
                        @foreach(CaseItemStatus::cases() as $status)<option value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach
                    </flux:select>
                    <div class="space-y-2">
                        <flux:select wire:model.live="form.institution_id" label="Institucija / primalac" size="sm" :disabled="! $canEdit">
                            <option value="">— nije odabrana —</option>
                            @foreach($this->institutions as $institution)<option value="{{ $institution->id }}">{{ $institution->name }}</option>@endforeach
                        </flux:select>
                        @if(! $task->institution_id)
                            <flux:input wire:model.live.blur="form.recipient" size="sm" placeholder="ili upišite primaoca" aria-label="Primalac" :disabled="! $canEdit" />
                        @endif
                    </div>
                    <div>
                        <flux:input wire:model.live="form.due_date" type="date" label="Rok" size="sm" :disabled="! $canEdit" />
                        @if($isOverdue)<p class="mt-1 text-xs font-medium text-red-600">Rok je prošao</p>@endif
                    </div>
                    <flux:input wire:model.live="form.submitted_at" type="date" label="Datum predaje" size="sm" :disabled="! $canEdit" />
                    <flux:input wire:model.live="form.response_at" type="date" label="Datum odgovora" size="sm" :disabled="! $canEdit" />
                    <flux:select wire:model.live="form.responsible_user_id" label="Odgovorna osoba" size="sm" :disabled="! $canEdit">
                        <option value="">Nije dodijeljeno</option>
                        @foreach($this->users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach
                    </flux:select>
                    <dl class="space-y-1.5 border-t border-zinc-100 pt-3 text-xs text-zinc-500 dark:border-zinc-800">
                        <div>Postupak: <span class="text-zinc-700 dark:text-zinc-300">{{ $task->administrativeCase->name }}</span></div>
                        <div>Kreirano: {{ local_time($task->created_at)->format('d.m.Y.') }}</div>
                        @if($task->completed_at)<div>Završeno: {{ local_time($task->completed_at)->format('d.m.Y.') }}</div>@endif
                    </dl>
                    <div wire:loading.delay class="text-xs text-zinc-400" role="status">Snimanje…</div>
                </aside>
            </div>
        </aside>
    @endif
</div>
