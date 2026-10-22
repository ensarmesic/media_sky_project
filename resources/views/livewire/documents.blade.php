<?php

use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Services\DocumentService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Dokumenti zapisa (projekat, postupak, stavka, komentar): drag & drop upload više
 * fajlova s progress barom po fajlu, opcionalni smjer/broj protokola/datum, verzije,
 * pregled (slike u lightboxu, PDF u pregledniku) i brisanje.
 */
new class extends Component {
    use WithFileUploads;

    /** project | case | item | comment */
    #[Locked]
    public string $ownerType;

    #[Locked]
    public int $ownerId;

    #[Locked]
    public bool $showList = true;

    /** Privremeni fajl koji se upravo uploaduje (fajlovi idu jedan po jedan). */
    public $upload;

    /** Fajl za novu verziju dokumenta. */
    public $versionUpload;

    public array $meta = ['direction' => '', 'protocol_number' => '', 'document_date' => ''];

    public ?int $expandedHistory = null;

    public function mount(string $ownerType, int $ownerId, bool $showList = true): void
    {
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
        $this->showList = $showList;
        $owner = $this->owner();
        abort_if($owner === null, 404);
        Gate::authorize('view', Project::owning($owner));
    }

    #[Computed]
    public function owner(): ?Model
    {
        $class = match ($this->ownerType) {
            'project' => Project::class,
            'case' => AdministrativeCase::class,
            'item' => AdministrativeCaseItem::class,
            'comment' => Comment::class,
            default => null,
        };

        return $class ? $class::find($this->ownerId) : null;
    }

    #[Computed]
    public function canUpload(): bool
    {
        return $this->owner() !== null && DocumentService::canUpload(auth()->user(), $this->owner());
    }

    #[Computed]
    public function documents(): Collection
    {
        return $this->owner()->documents()->whereNull('replaced_by_id')->with('uploader:id,name')->withCount('previousVersions')->latest()->latest('id')->get();
    }

    /** Poziva se nakon što se fajl uploaduje u $upload (jedan po jedan iz reda u pregledniku). */
    public function saveUpload(DocumentService $service): void
    {
        abort_unless($this->canUpload, 403);
        $this->validate(['upload' => DocumentService::fileRules()], DocumentService::fileMessages('upload'));
        $this->validate([
            'meta.direction' => ['nullable', 'in:'.implode(',', array_keys(ProjectDocument::DIRECTIONS))],
            'meta.protocol_number' => ['nullable', 'string', 'max:100'],
            'meta.document_date' => ['nullable', 'date'],
        ], attributes: ['meta.protocol_number' => 'broj protokola', 'meta.document_date' => 'datum dokumenta']);

        $service->store($this->owner(), auth()->user(), $this->upload, $this->meta);
        $this->reset('upload');
        unset($this->documents);
        $this->dispatch('documents-updated');
        $this->dispatch('task-updated');
    }

    public function finishBatch(int $count): void
    {
        $this->reset('meta');
        Flux::toast($count === 1 ? 'Dokument je dodan.' : "Dodano dokumenata: {$count}.", variant: 'success');
    }

    public function saveVersion(int $documentId, DocumentService $service): void
    {
        $document = $this->findDocument($documentId);
        $this->validate(['versionUpload' => DocumentService::fileRules()], DocumentService::fileMessages('versionUpload'));
        $newVersion = $service->replaceVersion($document, auth()->user(), $this->versionUpload);
        $this->reset('versionUpload');
        unset($this->documents);
        $this->dispatch('documents-updated');
        Flux::toast('Sačuvana je verzija '.$newVersion->version.'.', variant: 'success');
    }

    public function delete(int $documentId, DocumentService $service): void
    {
        $service->delete($this->findDocument($documentId), auth()->user());
        unset($this->documents);
        $this->dispatch('documents-updated');
        $this->dispatch('task-updated');
        Flux::toast('Dokument je obrisan.');
    }

    public function toggleHistory(int $documentId): void
    {
        $this->expandedHistory = $this->expandedHistory === $documentId ? null : $documentId;
    }

    private function findDocument(int $documentId): ProjectDocument
    {
        return $this->owner()->documents()->whereNull('replaced_by_id')->findOrFail($documentId);
    }
}; ?>

@php($maxMb = (int) round(config('projectflow.documents.max_size') / 1024))
@php($accept = collect(explode(',', config('projectflow.documents.allowed_extensions')))->map(fn ($ext) => '.'.$ext)->implode(','))
<div x-data="{
        queue: [],
        dragging: false,
        lightbox: null,
        maxBytes: {{ (int) config('projectflow.documents.max_size') * 1024 }},
        add(files) {
            const batch = Array.from(files).map(file => ({ id: Math.random().toString(36).slice(2), file, name: file.name, progress: 0, error: file.size > this.maxBytes ? 'Fajl je prevelik (najviše {{ $maxMb }} MB).' : null, done: false }));
            this.queue.push(...batch);
            this.process(batch.filter(entry => ! entry.error));
        },
        async process(entries) {
            let saved = 0;
            for (const entry of entries) {
                await new Promise(resolve => {
                    $wire.upload('upload', entry.file,
                        () => $wire.saveUpload().then(() => {
                            const errors = $wire.$el.querySelector('[data-upload-error]')?.textContent?.trim();
                            if (errors) { entry.error = errors } else { entry.done = true; saved++ }
                            resolve();
                        }),
                        () => { entry.error = 'Upload nije uspio. Provjerite vrstu i veličinu fajla.'; resolve() },
                        event => { entry.progress = event.detail.progress },
                    );
                });
            }
            if (saved > 0) { $wire.finishBatch(saved) }
            setTimeout(() => { this.queue = this.queue.filter(entry => ! entry.done) }, 1500);
        },
    }" class="space-y-3">

    @if($this->canUpload)
        {{-- Drag & drop zona + dugme --}}
        <div x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false" x-on:drop.prevent="dragging = false; add($event.dataTransfer.files)"
            :class="dragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-zinc-300 dark:border-zinc-700'"
            class="rounded-lg border-2 border-dashed p-4 text-center transition">
            <flux:icon.cloud-arrow-up class="mx-auto size-7 text-zinc-400" />
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                Prevucite fajlove ovdje ili
                <label class="cursor-pointer font-medium text-blue-600 hover:underline focus-within:underline">odaberite<input type="file" multiple accept="{{ $accept }}" class="sr-only" x-on:change="add($event.target.files); $event.target.value = ''" /></label>
            </p>
            <p class="mt-0.5 text-xs text-zinc-400">{{ config('projectflow.documents.allowed_labels') }} · najviše {{ $maxMb }} MB po fajlu</p>
            <details class="mx-auto mt-3 max-w-xl text-left">
                <summary class="cursor-pointer text-xs text-zinc-500">Podaci o dokumentu (opciono): smjer, broj protokola, datum</summary>
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    <flux:select wire:model="meta.direction" size="sm" aria-label="Smjer"><option value="">Smjer</option>@foreach(ProjectDocument::DIRECTIONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</flux:select>
                    <flux:input wire:model="meta.protocol_number" size="sm" placeholder="Broj protokola" aria-label="Broj protokola" />
                    <flux:input wire:model="meta.document_date" type="date" size="sm" aria-label="Datum dokumenta" />
                </div>
            </details>
        </div>
        <div data-upload-error class="sr-only">@error('upload'){{ $message }}@enderror</div>

        {{-- Red uploada s progress barom po fajlu --}}
        <ul x-show="queue.length" x-cloak class="space-y-2" aria-live="polite">
            <template x-for="entry in queue" :key="entry.id">
                <li class="rounded-lg border border-zinc-200 p-2 text-sm dark:border-zinc-700">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate" x-text="entry.name"></span>
                        <span class="shrink-0 text-xs" :class="entry.error ? 'text-red-600' : 'text-zinc-500'" x-text="entry.error ? 'Greška' : (entry.done ? 'Sačuvano' : entry.progress + '%')"></span>
                    </div>
                    <div class="mt-1 h-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800"><div class="h-full rounded-full transition-all" :class="entry.error ? 'bg-red-500' : 'bg-blue-600'" :style="`width: ${entry.error ? 100 : entry.progress}%`"></div></div>
                    <p x-show="entry.error" class="mt-1 text-xs text-red-600" x-text="entry.error"></p>
                </li>
            </template>
        </ul>
    @endif

    @if($showList)
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse($this->documents as $document)
                <li class="py-2.5" wire:key="doc-{{ $document->id }}">
                    <div class="flex items-center gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $document->iconClass() }}"><flux:icon :name="$document->icon()" variant="mini" class="size-4" /></span>
                        <div class="min-w-0 flex-1">
                            @if($document->isImage())
                                <button type="button" x-on:click="lightbox = { src: @js(route('documents.show', $document)), name: @js($document->name) }" class="block max-w-full truncate text-left text-sm font-medium hover:text-blue-600">{{ $document->name }}</button>
                            @else
                                <a href="{{ route('documents.show', $document) }}" @if($document->isPdf()) target="_blank" rel="noopener" @endif class="block truncate text-sm font-medium hover:text-blue-600">{{ $document->name }}</a>
                            @endif
                            <div class="truncate text-xs text-zinc-500">
                                {{ $document->humanSize() }} · {{ $document->uploader->shortName() }}, {{ local_time($document->created_at)->format('d.m.Y.') }}
                                @if($document->directionLabel()) · {{ $document->directionLabel() }}@endif
                                @if($document->protocol_number) · Br. {{ $document->protocol_number }}@endif
                                @if($document->document_date) · {{ $document->document_date->format('d.m.Y.') }}@endif
                            </div>
                        </div>
                        @if($document->version > 1)
                            <button type="button" wire:click="toggleHistory({{ $document->id }})" class="shrink-0 rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300" aria-expanded="{{ $expandedHistory === $document->id ? 'true' : 'false' }}">Verzija {{ $document->version }}</button>
                        @endif
                        @if($document->isImage())
                            <img src="{{ route('documents.show', $document) }}" alt="" loading="lazy" class="size-9 shrink-0 cursor-pointer rounded object-cover" x-on:click="lightbox = { src: @js(route('documents.show', $document)), name: @js($document->name) }" />
                        @endif
                        <flux:dropdown align="end">
                            <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Opcije za {{ $document->name }}" />
                            <flux:menu>
                                @if($document->isPreviewable())<flux:menu.item icon="eye" :href="route('documents.show', $document)" target="_blank">Otvori</flux:menu.item>@endif
                                <flux:menu.item icon="arrow-down-tray" :href="route('documents.download', $document)">Preuzmi</flux:menu.item>
                                @if($this->canUpload)
                                    <flux:menu.item icon="arrow-up-tray" x-on:click="$refs['version{{ $document->id }}'].click()">Nova verzija</flux:menu.item>
                                @endif
                                @if($document->version > 1)<flux:menu.item icon="clock" wire:click="toggleHistory({{ $document->id }})">Historija verzija</flux:menu.item>@endif
                                @can('delete', $document)
                                    <flux:menu.separator />
                                    <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $document->id }})" wire:confirm="Obrisati dokument „{{ $document->name }}“ i sve njegove verzije?">Obriši</flux:menu.item>
                                @endcan
                            </flux:menu>
                        </flux:dropdown>
                        @if($this->canUpload)
                            <input type="file" class="hidden" accept="{{ $accept }}" x-ref="version{{ $document->id }}" x-on:change="if ($event.target.files[0]) { $wire.upload('versionUpload', $event.target.files[0], () => $wire.saveVersion({{ $document->id }})) } $event.target.value = ''" />
                        @endif
                    </div>
                    @if($expandedHistory === $document->id)
                        <ul class="mt-2 ml-12 space-y-1 border-l border-zinc-200 pl-3 text-xs dark:border-zinc-700">
                            @foreach($document->versionHistory() as $previous)
                                <li class="flex items-center gap-2"><a href="{{ route('documents.download', $previous) }}" class="text-blue-600 hover:underline">Verzija {{ $previous->version }}</a><span class="text-zinc-500">· {{ $previous->original_name }} · {{ local_time($previous->created_at)->format('d.m.Y.') }} · {{ $previous->humanSize() }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @empty
                <li class="py-2 text-sm text-zinc-500">Nema dokumenata.</li>
            @endforelse
        </ul>
        @error('versionUpload')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    @endif

    {{-- Lightbox za slike --}}
    <template x-teleport="body">
        <div x-show="lightbox" x-cloak x-on:keydown.escape.window="lightbox = null" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/80 p-4" x-on:click.self="lightbox = null" role="dialog" aria-modal="true" aria-label="Pregled slike">
            <figure class="max-h-full max-w-5xl">
                <img :src="lightbox?.src" :alt="lightbox?.name" class="max-h-[85vh] rounded-lg object-contain" />
                <figcaption class="mt-2 flex items-center justify-between text-sm text-white"><span x-text="lightbox?.name"></span><button type="button" x-on:click="lightbox = null" class="rounded px-2 py-1 hover:bg-white/10">Zatvori ✕</button></figcaption>
            </figure>
        </div>
    </template>
</div>
