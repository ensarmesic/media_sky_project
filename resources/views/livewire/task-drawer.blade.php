<?php

use App\Enums\CaseItemPriority;
use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCaseItem;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Services\AdministrativeCaseItemService;
use App\Support\RichText;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Detalj zadatka. Na stranicama se otvara kao bočni panel (event "open-task",
 * URL parametar ?zadatak=ID), a na /zadaci/{id} se prikazuje kao cijela stranica.
 */
new class extends Component {
    #[Url(as: 'zadatak', except: null)]
    public ?int $openId = null;

    /** Postavljeno samo na stranici /zadaci/{id}. */
    #[Locked]
    public ?int $pageTaskId = null;

    public array $form = [];

    public string $newChecklistItem = '';

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
    public function open(int $id): void
    {
        $this->openId = $id;
        unset($this->task);
        if ($this->task() === null) {
            $this->openId = null;
            Flux::toast('Zadatak nije dostupan.', variant: 'danger');

            return;
        }
        $this->resetValidation();
        $this->newChecklistItem = '';
        $this->fillForm();
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

        $task = AdministrativeCaseItem::with(['administrativeCase.project', 'responsible', 'checklistItems'])
            ->where('is_active', true)
            ->find($id);

        return $task !== null && auth()->user()->can('view', $task) ? $task : null;
    }

    #[Computed]
    public function canEdit(): bool
    {
        return $this->task() !== null && auth()->user()->can('update', $this->task());
    }

    #[Computed]
    public function users(): Collection
    {
        return User::orderBy('name')->get(['id', 'name', 'username']);
    }

    /** Svako polje se snima odmah nakon izmjene. */
    public function updatedForm(mixed $value, string $key): void
    {
        $task = $this->editableTask();
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::enum(CaseItemStatus::class)],
            'priority' => ['required', Rule::enum(CaseItemPriority::class)],
            'responsible_user_id' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
        ];
        abort_unless(isset($rules[$key]), 422);

        $this->validate(["form.$key" => $rules[$key]], attributes: ["form.$key" => match ($key) {
            'name' => 'naziv', 'description' => 'opis', 'responsible_user_id' => 'dodijeljeni korisnik', 'due_date' => 'rok', default => $key,
        }]);

        app(AdministrativeCaseItemService::class)->update($task, [$key => $value === '' ? null : (is_string($value) && $key !== 'description' ? trim($value) : $value)], auth()->user());
        $this->saved();
    }

    public function markCompleted(AdministrativeCaseItemService $service): void
    {
        $service->changeStatus($this->editableTask(), CaseItemStatus::Completed, auth()->user());
        $this->form['status'] = CaseItemStatus::Completed->value;
        $this->saved('Zadatak je označen završenim.');
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
        Flux::toast('Zadatak je dupliciran.', variant: 'success');
    }

    public function delete(AdministrativeCaseItemService $service): void
    {
        $task = $this->editableTask();
        Gate::authorize('update', $task->administrativeCase);
        $service->deactivate($task, auth()->user());
        $this->dispatch('task-updated');
        Flux::toast('Zadatak je obrisan.');

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
        $this->validate(['newChecklistItem' => ['required', 'string', 'max:255']], attributes: ['newChecklistItem' => 'stavka']);
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
        $task = $this->task();
        $this->form = [
            'name' => $task->name,
            'description' => $task->description ?? '',
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'responsible_user_id' => (string) ($task->responsible_user_id ?? ''),
            'due_date' => $task->due_date?->format('Y-m-d') ?? '',
        ];
    }

    private function saved(?string $message = 'Sačuvano'): void
    {
        unset($this->task);
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
            @if(! $pageTaskId) x-data x-init="$nextTick(() => $el.focus())" x-on:keydown.escape.window="$wire.close()" tabindex="-1" role="dialog" aria-modal="true" @endif
            aria-labelledby="task-title-{{ $task->id }}"
            @class([
                'flex flex-col bg-white dark:bg-zinc-900 focus:outline-none',
                'fixed inset-y-0 right-0 z-50 w-full max-w-2xl overflow-y-auto border-l border-zinc-200 shadow-2xl dark:border-zinc-800' => ! $pageTaskId,
                'rounded-xl border border-zinc-200 dark:border-zinc-800' => $pageTaskId,
            ])>
            <span class="sr-only">Detalji zadatka</span>

            {{-- Zaglavlje: breadcrumb + akcije --}}
            <div class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-zinc-200 bg-white/95 px-5 py-3 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
                <nav class="min-w-0 truncate text-xs text-zinc-500" aria-label="Putanja">
                    <a href="{{ route('projects.show', $task->administrativeCase->project) }}" wire:navigate class="hover:text-blue-600">{{ $task->administrativeCase->project->name }}</a>
                    <span class="mx-1">›</span>
                    <a href="{{ route('cases.show', $task->administrativeCase) }}" wire:navigate class="hover:text-blue-600">{{ $task->administrativeCase->name }}</a>
                </nav>
                <div class="flex shrink-0 items-center gap-1">
                    @if($canEdit && $task->status !== CaseItemStatus::Completed)
                        <flux:button size="sm" icon="check" wire:click="markCompleted">Označi završenim</flux:button>
                    @endif
                    @can('update', $task->administrativeCase)
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Više akcija" />
                            <flux:menu>
                                <flux:menu.item icon="document-duplicate" wire:click="duplicate">Dupliciraj</flux:menu.item>
                                @unless($pageTaskId)<flux:menu.item icon="arrow-top-right-on-square" :href="route('tasks.show', $task)" wire:navigate>Otvori kao stranicu</flux:menu.item>@endunless
                                <flux:menu.separator />
                                <flux:menu.item icon="trash" variant="danger" wire:click="delete" wire:confirm="Obrisati zadatak? Istorija i prilozi ostaju sačuvani.">Obriši</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endcan
                    @unless($pageTaskId)
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="close" aria-label="Zatvori" />
                    @endunless
                </div>
            </div>

            <div class="grid gap-6 p-5 md:grid-cols-[1fr_14rem]">
                {{-- Glavni sadržaj --}}
                <div class="min-w-0 space-y-6">
                    {{-- Naslov (inline izmjena) --}}
                    @if($canEdit)
                        <div>
                            <label for="task-title-{{ $task->id }}" class="sr-only">Naziv zadatka</label>
                            <input id="task-title-{{ $task->id }}" type="text" wire:model.blur="form.name" x-on:keydown.enter.prevent="$el.blur()"
                                class="w-full rounded-md border border-transparent bg-transparent px-1 py-0.5 text-xl font-semibold hover:border-zinc-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:hover:border-zinc-700" />
                            <flux:error name="form.name" />
                        </div>
                    @else
                        <h2 id="task-title-{{ $task->id }}" class="text-xl font-semibold">{{ $task->name }}</h2>
                    @endif

                    {{-- Opis --}}
                    <section x-data="{ editing: false }" aria-label="Opis">
                        <div class="mb-1 flex items-center justify-between">
                            <h3 class="text-sm font-medium text-zinc-500">Opis</h3>
                            @if($canEdit)
                                <button type="button" x-show="! editing" x-on:click="editing = true; $nextTick(() => $refs.description.focus())" class="text-xs text-blue-600 hover:underline">Uredi</button>
                            @endif
                        </div>
                        <div x-show="! editing" @if($canEdit) x-on:dblclick="editing = true; $nextTick(() => $refs.description.focus())" @endif>
                            @if(filled($task->description))
                                <div class="rich-text text-zinc-700 dark:text-zinc-200">{{ RichText::render($task->description) }}</div>
                            @else
                                <p class="text-sm text-zinc-400">{{ $canEdit ? 'Dodajte opis (podržani su linkovi, **bold**, `kod` i liste)…' : 'Nema opisa.' }}</p>
                            @endif
                        </div>
                        @if($canEdit)
                            <div x-show="editing" x-cloak>
                                <textarea x-ref="description" wire:model.blur="form.description" x-on:blur="editing = false" rows="5"
                                    class="w-full rounded-lg border border-zinc-200 bg-white p-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-zinc-700 dark:bg-zinc-800"
                                    placeholder="Opis zadatka…"></textarea>
                                <p class="mt-1 text-xs text-zinc-400">Snima se kad napustite polje.</p>
                            </div>
                            <flux:error name="form.description" />
                        @endif
                    </section>

                    {{-- Checklist --}}
                    <section aria-labelledby="checklist-heading-{{ $task->id }}">
                        <div class="mb-2 flex items-center justify-between">
                            <h3 id="checklist-heading-{{ $task->id }}" class="text-sm font-medium text-zinc-500">Checklist</h3>
                            @if($checklist->isNotEmpty())
                                <span class="text-xs text-zinc-500">{{ $checklist->where('is_done', true)->count() }}/{{ $checklist->count() }}</span>
                            @endif
                        </div>
                        @if($checklist->isNotEmpty())
                            <div class="mb-2 h-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"><div class="h-full rounded-full bg-emerald-500" style="width: {{ (int) round($checklist->where('is_done', true)->count() / $checklist->count() * 100) }}%"></div></div>
                        @endif
                        <ul @if($canEdit) wire:sort="sortChecklist" @endif class="space-y-1">
                            @foreach($checklist as $checklistItem)
                                <li wire:key="checklist-{{ $checklistItem->id }}" @if($canEdit) wire:sort:item="{{ $checklistItem->id }}" @endif class="group flex items-center gap-2 rounded-md px-1 py-1 hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                    @if($canEdit)<span wire:sort:handle class="cursor-grab text-zinc-300 opacity-0 group-hover:opacity-100" aria-hidden="true"><flux:icon.bars-2 variant="micro" class="size-4" /></span>@endif
                                    <input type="checkbox" id="checklist-item-{{ $checklistItem->id }}" @checked($checklistItem->is_done) @disabled(! $canEdit) wire:click="toggleChecklistItem({{ $checklistItem->id }})" class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600" />
                                    <label for="checklist-item-{{ $checklistItem->id }}" @class(['flex-1 text-sm', 'text-zinc-400 line-through' => $checklistItem->is_done])>{{ $checklistItem->title }}</label>
                                    @if($canEdit)
                                        <button type="button" wire:click="deleteChecklistItem({{ $checklistItem->id }})" class="text-zinc-300 opacity-0 hover:text-red-600 focus:opacity-100 group-hover:opacity-100" aria-label="Obriši stavku {{ $checklistItem->title }}"><flux:icon.x-mark variant="micro" class="size-4" /></button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if($canEdit)
                            <form wire:submit="addChecklistItem" class="mt-1">
                                <input type="text" wire:model="newChecklistItem" placeholder="+ Dodaj stavku (Enter)" aria-label="Nova stavka checkliste"
                                    class="w-full rounded-md border border-transparent bg-transparent px-2 py-1 text-sm placeholder:text-zinc-400 hover:border-zinc-200 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:hover:border-zinc-700" />
                                <flux:error name="newChecklistItem" />
                            </form>
                        @elseif($checklist->isEmpty())
                            <p class="text-sm text-zinc-400">Nema stavki.</p>
                        @endif
                    </section>

                    {{-- Prilozi, linkovi, komentari i aktivnost --}}
                    @includeIf('livewire.partials.task-drawer-sections', ['task' => $task])
                </div>

                {{-- Polja uz bok --}}
                <aside class="space-y-4 md:border-l md:border-zinc-100 md:pl-5 md:dark:border-zinc-800" aria-label="Svojstva zadatka">
                    <flux:select wire:model.live="form.status" label="Status" size="sm" :disabled="! $canEdit">
                        @foreach(CaseItemStatus::cases() as $status)<option value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model.live="form.priority" label="Prioritet" size="sm" :disabled="! $canEdit">
                        @foreach(CaseItemPriority::cases() as $priority)<option value="{{ $priority->value }}">{{ $priority->label() }}</option>@endforeach
                    </flux:select>
                    <flux:select wire:model.live="form.responsible_user_id" label="Dodijeljeno" size="sm" :disabled="! $canEdit">
                        <option value="">Nije dodijeljeno</option>
                        @foreach($this->users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach
                    </flux:select>
                    <div>
                        <flux:input wire:model.live="form.due_date" type="date" label="Rok" size="sm" :disabled="! $canEdit" />
                        @if($isOverdue)<p class="mt-1 text-xs font-medium text-red-600">Kasni</p>@endif
                    </div>
                    <dl class="space-y-2 border-t border-zinc-100 pt-3 text-xs text-zinc-500 dark:border-zinc-800">
                        <div><dt class="inline">Predmet:</dt> <dd class="inline text-zinc-700 dark:text-zinc-300">{{ $task->administrativeCase->name }}</dd></div>
                        <div><dt class="inline">Kreirano:</dt> <dd class="inline">{{ $task->created_at->format('d.m.Y.') }}</dd></div>
                        @if($task->completed_at)<div><dt class="inline">Završeno:</dt> <dd class="inline">{{ $task->completed_at->format('d.m.Y. H:i') }}</dd></div>@endif
                    </dl>
                    <div wire:loading.delay class="text-xs text-zinc-400" role="status">Snimanje…</div>
                </aside>
            </div>
        </aside>
    @endif
</div>
