<?php

use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Comment;
use App\Models\ProjectDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Detalj postupka (bočni panel): dokumenti postupka, svi dokumenti s njegovih stavki, linkovi i komentari. */
new class extends Component {
    #[Url(as: 'postupak', except: null)]
    public ?int $caseId = null;

    #[On('open-case')]
    public function open(int $id): void
    {
        $this->caseId = $id;
        unset($this->case);
        if ($this->case() === null) {
            $this->caseId = null;
        }
    }

    public function close(): void
    {
        $this->caseId = null;
        unset($this->case);
    }

    #[Computed]
    public function case(): ?AdministrativeCase
    {
        if ($this->caseId === null) {
            return null;
        }
        $case = AdministrativeCase::with(['project', 'institution'])->find($this->caseId);

        return $case !== null && auth()->user()->can('view', $case) ? $case : null;
    }

    /** "Svi dokumenti": dokumenti postupka, njegovih stavki i komentara na njima. */
    #[Computed]
    public function allDocuments(): Collection
    {
        $case = $this->case();
        $itemIds = $case->items()->select('id');
        $itemType = (new AdministrativeCaseItem)->getMorphClass();
        $caseType = $case->getMorphClass();

        return ProjectDocument::query()
            ->whereNull('replaced_by_id')
            ->with(['documentable', 'uploader:id,name'])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('documentable_type', $caseType)->where('documentable_id', $case->id))
                ->orWhere(fn (Builder $q) => $q->where('documentable_type', $itemType)->whereIn('documentable_id', $itemIds))
                ->orWhere(fn (Builder $q) => $q->where('documentable_type', (new Comment)->getMorphClass())->whereIn('documentable_id', Comment::query()->select('id')->where(fn (Builder $c) => $c
                    ->where(fn (Builder $x) => $x->where('commentable_type', $caseType)->where('commentable_id', $case->id))
                    ->orWhere(fn (Builder $x) => $x->where('commentable_type', $itemType)->whereIn('commentable_id', $itemIds))))))
            ->latest()->latest('id')
            ->get();
    }

    #[On('documents-updated')]
    public function refreshDocuments(): void
    {
        unset($this->allDocuments);
    }
}; ?>

<div>
    @if($case = $this->case)
        <div class="fixed inset-0 z-40 bg-black/30" wire:click="close" aria-hidden="true"></div>
        <aside x-data x-init="$nextTick(() => $el.focus())" x-on:keydown.escape.window="$wire.close()" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="case-drawer-title"
            class="fixed inset-y-0 right-0 z-50 flex w-full max-w-2xl flex-col overflow-y-auto border-l border-zinc-200 bg-white shadow-2xl focus:outline-none dark:border-zinc-800 dark:bg-zinc-900">
            <div class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-zinc-200 bg-white/95 px-5 py-3 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
                <div class="min-w-0">
                    <div class="text-xs text-zinc-500">{{ $case->project->name }} › Postupak</div>
                    <h2 id="case-drawer-title" class="truncate text-lg font-semibold">{{ $case->name }}</h2>
                    <div class="text-sm text-zinc-500">{{ $case->institution?->name ?? 'Bez institucije' }} · <span class="{{ $case->progressStatus()->color() === 'zinc' ? 'text-zinc-500' : '' }}">{{ $case->progressStatus()->label() }}</span></div>
                </div>
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="close" aria-label="Zatvori" />
            </div>

            <div class="space-y-6 p-5">
                <section aria-labelledby="case-docs-heading">
                    <h3 id="case-docs-heading" class="mb-2 text-sm font-medium text-zinc-500">Dokumenti postupka</h3>
                    <livewire:documents owner-type="case" :owner-id="$case->id" wire:key="case-documents-{{ $case->id }}" />
                </section>

                <section aria-labelledby="case-all-docs-heading">
                    <h3 id="case-all-docs-heading" class="mb-2 text-sm font-medium text-zinc-500">Svi dokumenti (postupak i stavke) <span class="text-zinc-400">· {{ $this->allDocuments->count() }}</span></h3>
                    <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                        @forelse($this->allDocuments as $document)
                            @php($owner = $document->documentable)
                            <li class="flex items-center gap-3 px-3 py-2" wire:key="all-doc-{{ $document->id }}">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg {{ $document->iconClass() }}"><flux:icon :name="$document->icon()" variant="mini" class="size-4" /></span>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('documents.show', $document) }}" target="_blank" rel="noopener" class="block truncate text-sm font-medium hover:text-blue-600">{{ $document->name }}</a>
                                    <div class="truncate text-xs text-zinc-500">
                                        {{ match (true) { $owner instanceof AdministrativeCaseItem => 'Stavka: '.$owner->name, $owner instanceof Comment => 'Prilog komentaru', default => 'Postupak' } }}
                                        · {{ ($document->document_date ?? $document->created_at)->format('d.m.Y.') }}{{ $document->protocol_number ? ' · Br. '.$document->protocol_number : '' }}
                                    </div>
                                </div>
                                <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('documents.download', $document)" aria-label="Preuzmi {{ $document->name }}" />
                            </li>
                        @empty
                            <li class="px-3 py-3 text-sm text-zinc-500">Nema dokumenata na postupku ni na njegovim stavkama.</li>
                        @endforelse
                    </ul>
                </section>

                @includeIf('livewire.partials.case-drawer-sections', ['case' => $case])
            </div>
        </aside>
    @endif
</div>
