<?php

use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Project;
use App\Models\ProjectDocument;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** Globalna pretraga (Ctrl/Cmd + K): projekti, postupci, stavke i dokumenti iz vidljivih projekata. */
new class extends Component {
    public string $query = '';

    /** @return array<string, \Illuminate\Support\Collection> */
    #[Computed]
    public function results(): array
    {
        $term = trim($this->query);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $projectIds = Project::visibleTo(auth()->user())->select('projects.id');

        return array_filter([
            'Projekti' => Project::visibleTo(auth()->user())
                ->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('location', 'like', $like)->orWhere('description', 'like', $like))
                ->orderBy('name')->limit(5)->get(['id', 'name', 'location'])
                ->map(fn (Project $project) => ['title' => $project->name, 'meta' => $project->location, 'url' => route('projects.show', $project), 'icon' => 'folder', 'task' => null]),
            'Postupci' => AdministrativeCase::with('project:id,name')->whereIn('project_id', $projectIds)->where('name', 'like', $like)
                ->orderBy('name')->limit(5)->get(['id', 'project_id', 'name'])
                ->map(fn (AdministrativeCase $case) => ['title' => $case->name, 'meta' => $case->project->name, 'url' => $case->url(), 'icon' => 'clipboard-document-list', 'task' => null]),
            'Stavke' => AdministrativeCaseItem::with('administrativeCase:id,project_id,name')->where('is_active', true)->where('name', 'like', $like)
                ->whereHas('administrativeCase', fn (Builder $query) => $query->whereIn('project_id', $projectIds))
                ->orderBy('name')->limit(8)->get(['id', 'administrative_case_id', 'name', 'status'])
                ->map(fn (AdministrativeCaseItem $item) => ['title' => $item->name, 'meta' => $item->administrativeCase->name.' · '.$item->status->label(), 'url' => $item->url(), 'icon' => 'check-circle', 'task' => $item->id]),
            'Dokumenti' => ProjectDocument::with('project:id,name')->whereIn('project_id', $projectIds)->whereNull('replaced_by_id')
                ->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('protocol_number', 'like', $like)->orWhere('original_name', 'like', $like))
                ->latest()->limit(5)->get(['id', 'project_id', 'name', 'protocol_number'])
                ->map(fn (ProjectDocument $document) => ['title' => $document->name, 'meta' => trim(($document->protocol_number ? $document->protocol_number.' · ' : '').$document->project->name), 'url' => route('projects.documents.download', [$document->project_id, $document]), 'icon' => 'document-text', 'task' => null]),
        ], fn ($group) => $group->isNotEmpty());
    }
}; ?>

<div x-data="{ open: false }"
    x-on:open-search.window="open = true; $nextTick(() => $refs.input.focus())"
    x-on:keydown.window.ctrl.k.prevent="open = ! open; if (open) $nextTick(() => $refs.input.focus())"
    x-on:keydown.window.meta.k.prevent="open = ! open; if (open) $nextTick(() => $refs.input.focus())"
    x-on:keydown.escape.window="open = false">
    <div x-show="open" x-cloak class="fixed inset-0 z-[60] bg-black/30" x-on:click="open = false" aria-hidden="true"></div>
    <div x-show="open" x-cloak x-trap.noscroll="open" role="dialog" aria-modal="true" aria-label="Pretraga"
        x-on:keydown.down.prevent="$focus.wrap().next()" x-on:keydown.up.prevent="$focus.wrap().previous()"
        class="fixed inset-x-4 top-16 z-[61] mx-auto max-w-xl overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center gap-2 border-b border-zinc-200 px-4 dark:border-zinc-700">
            <flux:icon.magnifying-glass class="size-5 text-zinc-400" />
            <input x-ref="input" type="search" wire:model.live.debounce.250ms="query" placeholder="Pretraži projekte, postupke, stavke i dokumente…" aria-label="Pojam za pretragu"
                class="h-12 flex-1 border-0 bg-transparent text-sm focus:outline-none focus:ring-0" />
            <kbd class="rounded border border-zinc-200 px-1.5 py-0.5 text-[11px] text-zinc-400 dark:border-zinc-600">Esc</kbd>
        </div>
        <div class="max-h-[60vh] overflow-y-auto p-2">
            <div wire:loading.delay class="px-3 py-2 text-sm text-zinc-400" role="status">Pretraga…</div>
            @if(mb_strlen(trim($query)) < 2)
                <p class="px-3 py-6 text-center text-sm text-zinc-400">Upišite najmanje 2 znaka. Strelice ↑ ↓ za kretanje, Enter za otvaranje.</p>
            @else
                @forelse($this->results as $group => $results)
                    <div class="mb-2">
                        <div class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ $group }}</div>
                        @foreach($results as $result)
                            <a href="{{ $result['url'] }}" @if(! $result['task']) wire:navigate @endif
                                @if($result['task']) x-on:click="if (window.Livewire?.getByName?.('task-drawer')?.length) { $event.preventDefault(); open = false; $dispatch('open-task', { id: {{ $result['task'] }} }) }" @else x-on:click="open = false" @endif
                                class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm hover:bg-zinc-100 focus:bg-blue-50 focus:outline-none dark:hover:bg-zinc-800 dark:focus:bg-blue-950/40">
                                <flux:icon :name="$result['icon']" class="size-4 shrink-0 text-zinc-400" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium">{{ $result['title'] }}</span>
                                    @if($result['meta'])<span class="block truncate text-xs text-zinc-500">{{ $result['meta'] }}</span>@endif
                                </span>
                            </a>
                        @endforeach
                    </div>
                @empty
                    <p class="px-3 py-6 text-center text-sm text-zinc-500">Nema rezultata za „{{ $query }}“.</p>
                @endforelse
            @endif
        </div>
    </div>
</div>
