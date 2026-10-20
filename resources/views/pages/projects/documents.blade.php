<?php

use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Svi dokumenti projekta (projekat, postupci, stavke, komentari) s filterima. */
new #[Title('Dokumenti projekta')] class extends Component {
    use WithPagination;

    public Project $project;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'postupak', except: '')]
    public string $caseId = '';

    #[Url(as: 'smjer', except: '')]
    public string $direction = '';

    /** pdf | image | office | other */
    #[Url(as: 'tip', except: '')]
    public string $type = '';

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
        $this->project = $project;
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function cases()
    {
        return $this->project->administrativeCases()->get(['id', 'name']);
    }

    #[Computed]
    public function documents()
    {
        $search = trim($this->search);
        $caseItemIds = fn (int $caseId) => AdministrativeCaseItem::where('administrative_case_id', $caseId)->select('id');

        return $this->project->documents()
            ->whereNull('replaced_by_id')
            ->with(['uploader:id,name', 'documentable'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where('name', 'like', "%{$search}%")->orWhere('original_name', 'like', "%{$search}%")->orWhere('protocol_number', 'like', "%{$search}%")))
            ->when($this->direction !== '', fn (Builder $query) => $query->where('direction', $this->direction))
            ->when($this->type === 'pdf', fn (Builder $query) => $query->where('mime_type', 'application/pdf'))
            ->when($this->type === 'image', fn (Builder $query) => $query->where('mime_type', 'like', 'image/%'))
            ->when($this->type === 'office', fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->where('mime_type', 'like', 'application/vnd.%')->orWhere('mime_type', 'application/msword')))
            ->when($this->type === 'other', fn (Builder $query) => $query->where('mime_type', '!=', 'application/pdf')->where('mime_type', 'not like', 'image/%')->where('mime_type', 'not like', 'application/vnd.%')->where('mime_type', '!=', 'application/msword'))
            ->when($this->caseId !== '', fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where(fn (Builder $owner) => $owner->where('documentable_type', (new AdministrativeCase)->getMorphClass())->where('documentable_id', $this->caseId))
                ->orWhere(fn (Builder $owner) => $owner->where('documentable_type', (new AdministrativeCaseItem)->getMorphClass())->whereIn('documentable_id', $caseItemIds((int) $this->caseId)))
                ->orWhere(fn (Builder $owner) => $owner->where('documentable_type', (new Comment)->getMorphClass())->whereIn('documentable_id', Comment::query()->select('id')->where(fn (Builder $comment) => $comment
                    ->where(fn (Builder $c) => $c->where('commentable_type', (new AdministrativeCase)->getMorphClass())->where('commentable_id', $this->caseId))
                    ->orWhere(fn (Builder $c) => $c->where('commentable_type', (new AdministrativeCaseItem)->getMorphClass())->whereIn('commentable_id', $caseItemIds((int) $this->caseId))))))))
            ->latest()->latest('id')
            ->paginate(25);
    }

    public function delete(int $documentId, \App\Services\DocumentService $service): void
    {
        $service->delete($this->project->documents()->whereNull('replaced_by_id')->findOrFail($documentId), auth()->user());
        unset($this->documents);
        \Flux\Flux::toast('Dokument je obrisan.');
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'caseId', 'direction', 'type');
    }

    #[On('documents-updated')]
    public function refreshDocuments(): void
    {
        unset($this->documents);
    }
}; ?>

<div class="mx-auto flex w-full max-w-6xl flex-col gap-5">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projekti</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('projects.show', $project)" wire:navigate>{{ $project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Dokumenti</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">Dokumenti projekta</flux:heading>
            <flux:subheading class="mt-1">Svi dokumenti s projekta, postupaka, stavki i komentara</flux:subheading>
        </div>
    </div>

    @can('update', $project)
        <section class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <flux:heading size="sm" class="mb-3">Dodaj dokument na projekat</flux:heading>
            <livewire:documents :owner-type="'project'" :owner-id="$project->id" :show-list="false" wire:key="project-documents-upload" />
        </section>
    @endcan

    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-3 lg:flex-row lg:items-center dark:border-zinc-800 dark:bg-zinc-900">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Naziv ili broj protokola…" class="lg:max-w-xs" aria-label="Pretraga dokumenata" clearable />
        <flux:select wire:model.live="caseId" class="lg:max-w-60" aria-label="Postupak">
            <option value="">Svi postupci</option>
            @foreach($this->cases as $case)<option value="{{ $case->id }}">{{ $case->name }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="direction" class="lg:max-w-40" aria-label="Smjer">
            <option value="">Svaki smjer</option>
            @foreach(ProjectDocument::DIRECTIONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </flux:select>
        <flux:select wire:model.live="type" class="lg:max-w-40" aria-label="Tip">
            <option value="">Svi tipovi</option>
            <option value="pdf">PDF</option>
            <option value="image">Slike</option>
            <option value="office">Office</option>
            <option value="other">Ostalo</option>
        </flux:select>
    </div>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" wire:loading.delay.class="opacity-60">
        @forelse($this->documents as $document)
            @php($owner = $document->documentable)
            <div class="flex flex-col gap-2 border-b border-zinc-100 p-4 last:border-0 sm:flex-row sm:items-center dark:border-zinc-800" wire:key="document-{{ $document->id }}">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg {{ $document->iconClass() }}"><flux:icon :name="$document->icon()" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <a href="{{ route('documents.show', $document) }}" target="_blank" rel="noopener" class="font-medium hover:text-blue-600">{{ $document->name }}</a>
                    @if($document->version > 1)<flux:badge size="sm" color="zinc" class="ml-1">Verzija {{ $document->version }}</flux:badge>@endif
                    <div class="mt-0.5 text-xs text-zinc-500">
                        {{ match (true) { $owner instanceof AdministrativeCaseItem => 'Stavka: '.$owner->name, $owner instanceof AdministrativeCase => 'Postupak: '.$owner->name, $owner instanceof Comment => 'Prilog komentaru', default => 'Projekat' } }}
                        · {{ ($document->document_date ?? $document->created_at)->format('d.m.Y.') }}
                        @if($document->directionLabel()) · {{ $document->directionLabel() }}@endif
                        @if($document->protocol_number) · Br. {{ $document->protocol_number }}@endif
                        · {{ $document->humanSize() }} · {{ $document->uploader->shortName() }}
                    </div>
                </div>
                <div class="flex shrink-0 gap-1">
                    <flux:button size="sm" variant="ghost" icon="eye" :href="route('documents.show', $document)" target="_blank" aria-label="Otvori {{ $document->name }}" />
                    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('documents.download', $document)" aria-label="Preuzmi {{ $document->name }}" />
                    @can('delete', $document)<flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $document->id }})" wire:confirm="Obrisati dokument „{{ $document->name }}“ i sve njegove verzije?" aria-label="Obriši {{ $document->name }}" />@endcan
                </div>
            </div>
        @empty
            <div class="px-6 py-14 text-center">
                <flux:icon.document class="mx-auto size-10 text-zinc-400" />
                <flux:heading class="mt-3">Nema dokumenata</flux:heading>
                @if($search !== '' || $caseId !== '' || $direction !== '' || $type !== '')<flux:button class="mt-4" wire:click="clearFilters">Očisti filtere</flux:button>@endif
            </div>
        @endforelse
    </section>
    {{ $this->documents->links() }}
</div>
