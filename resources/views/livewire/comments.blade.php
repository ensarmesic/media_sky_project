<?php

use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Comment;
use App\Models\Project;
use App\Policies\CommentPolicy;
use App\Services\CommentService;
use App\Services\DocumentService;
use App\Support\ActivityPresenter;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Komentari na stavci ili postupku (+ prekidač "Komentari | Aktivnost | Sve").
 * Tekst: automatski linkovi, osnovno formatiranje, @spominjanje; prilozi u komentaru.
 */
new class extends Component {
    use WithFileUploads;

    /** item | case */
    #[Locked]
    public string $ownerType;

    #[Locked]
    public int $ownerId;

    /** comments | activity | all */
    public string $mode = 'comments';

    public string $body = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    public ?int $editingId = null;

    public string $editBody = '';

    public function mount(string $ownerType, int $ownerId): void
    {
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
        abort_if($this->owner() === null, 404);
        Gate::authorize('view', Project::owning($this->owner()));
    }

    #[Computed]
    public function owner(): AdministrativeCaseItem|AdministrativeCase|null
    {
        return $this->ownerType === 'item' ? AdministrativeCaseItem::find($this->ownerId) : AdministrativeCase::find($this->ownerId);
    }

    #[Computed]
    public function canComment(): bool
    {
        return CommentPolicy::canComment(auth()->user(), Project::owning($this->owner()));
    }

    /** Članovi projekta za @spominjanje. */
    #[Computed]
    public function members(): Collection
    {
        return Project::owning($this->owner())->members()->orderBy('name')->get(['users.id', 'users.name', 'users.username']);
    }

    /**
     * Hronološki niz komentara i/ili zapisa aktivnosti, zavisno od prekidača.
     *
     * @return Collection<int, array{type: string, at: \Carbon\CarbonInterface, model: Model}>
     */
    #[Computed]
    public function timeline(): Collection
    {
        $entries = collect();
        if ($this->mode !== 'activity') {
            $entries = $entries->merge($this->owner()->comments()->with(['user:id,name,username', 'documents'])->get()
                ->map(fn (Comment $comment) => ['type' => 'comment', 'at' => $comment->created_at, 'model' => $comment]));
        }
        if ($this->mode !== 'comments') {
            $entries = $entries->merge($this->owner()->activityLog()->with('user:id,name,grammatical_gender')->where('event_type', 'not like', 'comment.%')->get()
                ->map(fn ($activity) => ['type' => 'activity', 'at' => $activity->created_at, 'model' => $activity]));
        }

        return $entries->sortBy(fn (array $entry) => [$entry['at']->timestamp, $entry['model']->id])->values();
    }

    public function save(CommentService $service): void
    {
        abort_unless($this->canComment, 403);
        $this->validate([
            'body' => [count($this->files) ? 'nullable' : 'required', 'string', 'max:5000'],
            'files' => ['array', 'max:10'],
            'files.*' => DocumentService::fileRules(),
        ], [
            'body.required' => 'Upišite komentar.',
            ...collect(DocumentService::fileMessages('files.*'))->all(),
        ]);

        $service->create($this->owner(), auth()->user(), $this->body !== '' ? $this->body : '📎', $this->files);
        $this->reset('body', 'files');
        unset($this->timeline);
        $this->dispatch('task-updated');
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function edit(int $id): void
    {
        $comment = $this->findComment($id);
        Gate::authorize('update', $comment);
        $this->editingId = $comment->id;
        $this->editBody = $comment->body;
    }

    public function update(CommentService $service): void
    {
        $this->validate(['editBody' => ['required', 'string', 'max:5000']], ['editBody.required' => 'Komentar ne može biti prazan.']);
        $service->update($this->findComment($this->editingId), auth()->user(), $this->editBody);
        $this->reset('editingId', 'editBody');
        unset($this->timeline);
    }

    public function delete(int $id, CommentService $service): void
    {
        $service->delete($this->findComment($id), auth()->user());
        unset($this->timeline);
        $this->dispatch('task-updated');
        Flux::toast('Komentar je obrisan.');
    }

    #[On('task-updated')]
    public function refreshTimeline(): void
    {
        unset($this->timeline);
    }

    private function findComment(?int $id): Comment
    {
        return $this->owner()->comments()->findOrFail($id);
    }
}; ?>

@php($usernames = $this->members->pluck('username')->filter()->all())
<div class="space-y-4">
    <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 text-sm dark:bg-zinc-800" role="tablist" aria-label="Prikaz">
        @foreach(['comments' => 'Komentari', 'activity' => 'Aktivnost', 'all' => 'Sve'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $mode === $key ? 'true' : 'false' }}" wire:click="$set('mode', '{{ $key }}')" @class(['flex-1 rounded-md px-3 py-1', 'bg-white font-medium shadow-sm dark:bg-zinc-700' => $mode === $key, 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $mode !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <ol class="space-y-4">
        @forelse($this->timeline as $entry)
            @if($entry['type'] === 'comment')
                @php($comment = $entry['model'])
                <li id="komentar-{{ $comment->id }}" class="group flex scroll-mt-20 gap-3" wire:key="comment-{{ $comment->id }}">
                    <span class="pf-avatar size-8! text-xs!" aria-hidden="true">{{ $comment->user->initials() }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-x-2 text-xs text-zinc-500">
                            <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $comment->user->name }}</span>
                            <time datetime="{{ $comment->created_at->toIso8601String() }}" title="{{ $comment->created_at->format('d.m.Y. H:i') }}">{{ $comment->created_at->format('d.m.Y. H:i') }}</time>
                            @if($comment->edited_at)<span title="Uređeno {{ $comment->edited_at->format('d.m.Y. H:i') }}">· uređeno</span>@endif
                            <span class="ml-auto flex gap-2 opacity-0 focus-within:opacity-100 group-hover:opacity-100">
                                @can('update', $comment)<button type="button" wire:click="edit({{ $comment->id }})" class="hover:text-blue-600">Uredi</button>@endcan
                                @can('delete', $comment)<button type="button" wire:click="delete({{ $comment->id }})" wire:confirm="Obrisati komentar?" class="hover:text-red-600">Obriši</button>@endcan
                            </span>
                        </div>
                        @if($editingId === $comment->id)
                            <form wire:submit="update" class="mt-1 space-y-2" x-data x-on:keydown.ctrl.enter.prevent="$wire.update()" x-on:keydown.meta.enter.prevent="$wire.update()">
                                <textarea wire:model="editBody" rows="3" aria-label="Uredi komentar" class="w-full rounded-lg border border-zinc-200 bg-white p-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30 dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                                <flux:error name="editBody" />
                                <div class="flex gap-2"><flux:button type="submit" size="sm" variant="primary">Sačuvaj</flux:button><flux:button type="button" size="sm" variant="ghost" wire:click="$set('editingId', null)">Odustani</flux:button></div>
                            </form>
                        @else
                            <div class="rich-text mt-0.5 text-zinc-700 dark:text-zinc-200">{{ App\Support\RichText::render($comment->body, $usernames) }}</div>
                        @endif
                        @if($comment->documents->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($comment->documents as $document)
                                    <a href="{{ route('documents.show', $document) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2 py-1 text-xs hover:border-blue-300 hover:text-blue-600 dark:border-zinc-700">
                                        <flux:icon :name="$document->icon()" variant="micro" class="size-3.5" />{{ $document->name }} <span class="text-zinc-400">{{ $document->humanSize() }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </li>
            @else
                @php($activity = $entry['model'])
                <li class="flex gap-3 text-sm text-zinc-600 dark:text-zinc-300" wire:key="activity-{{ $activity->id }}">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800"><flux:icon :name="$activity->icon()" variant="micro" class="size-4" /></span>
                    <p class="min-w-0 flex-1 pt-1.5">{{ ActivityPresenter::sentence($activity) }} <span class="text-xs text-zinc-400">· {{ $activity->created_at->format('d.m.Y.') }}</span></p>
                </li>
            @endif
        @empty
            <li class="text-sm text-zinc-400">{{ $mode === 'activity' ? 'Nema zabilježenih promjena.' : 'Još nema komentara.' }}</li>
        @endforelse
    </ol>

    @if($this->canComment && $mode !== 'activity')
        <form wire:submit="save" x-data="commentBox(@js($this->members->map(fn ($m) => ['username' => $m->username, 'name' => $m->name])->values()))"
            x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false" x-on:drop.prevent="dragging = false; $wire.uploadMultiple('files', $event.dataTransfer.files)"
            :class="dragging && 'ring-2 ring-blue-500'" class="relative rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <label for="comment-{{ $ownerType }}-{{ $ownerId }}" class="sr-only">Novi komentar</label>
            <textarea id="comment-{{ $ownerType }}-{{ $ownerId }}" x-ref="textarea" wire:model="body" rows="3" x-on:input="detect($event)" x-on:keydown="keydown($event)" x-on:click="detect($event)"
                placeholder="Napišite komentar… (@ za spominjanje, Ctrl+Enter za slanje)"
                class="block w-full resize-y rounded-t-lg border-0 bg-transparent p-3 text-sm focus:outline-none focus:ring-0"></textarea>

            {{-- @spominjanje: autocomplete --}}
            <ul x-show="open && results.length" x-cloak class="absolute left-3 top-full z-30 mt-1 w-64 rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900" role="listbox" aria-label="Članovi projekta">
                <template x-for="(member, i) in results" :key="member.username">
                    <li role="option" :aria-selected="i === index" x-on:mousedown.prevent="pick(member)" :class="i === index && 'bg-blue-50 dark:bg-blue-950/40'" class="flex cursor-pointer items-center justify-between rounded-md px-2 py-1.5 text-sm">
                        <span x-text="member.name"></span><span class="text-xs text-zinc-400" x-text="'@' + member.username"></span>
                    </li>
                </template>
            </ul>

            @if($files)
                <ul class="flex flex-wrap gap-2 px-3 pb-2">
                    @foreach($files as $index => $file)
                        <li class="inline-flex items-center gap-1 rounded-md bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-800" wire:key="file-{{ $index }}">
                            {{ $file->getClientOriginalName() }}
                            <button type="button" wire:click="removeFile({{ $index }})" class="text-zinc-400 hover:text-red-600" aria-label="Ukloni prilog">×</button>
                        </li>
                    @endforeach
                </ul>
            @endif
            @error('files.*')<p class="px-3 pb-2 text-xs text-red-600">{{ $message }}</p>@enderror

            <div class="flex items-center justify-between gap-2 border-t border-zinc-100 px-2 py-1.5 dark:border-zinc-800">
                <label class="pf-icon-button flex cursor-pointer items-center gap-1 text-xs" title="Priloži fajl">
                    <flux:icon.paper-clip variant="micro" class="size-4" /> Prilog
                    <input type="file" multiple wire:model="files" class="sr-only" aria-label="Priloži fajlove" />
                </label>
                <div class="flex items-center gap-2">
                    <span wire:loading wire:target="files" class="text-xs text-zinc-400">Upload…</span>
                    <span class="hidden text-xs text-zinc-400 sm:inline">Ctrl + Enter</span>
                    <flux:button type="submit" size="sm" variant="primary">Pošalji</flux:button>
                </div>
            </div>
            <flux:error name="body" class="px-3 pb-2" />
        </form>
    @endif
</div>

