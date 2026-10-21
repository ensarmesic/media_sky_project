<?php

use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Link;
use App\Models\Project;
use App\Services\LinkService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Linkovi na stavci, postupku ili projektu: dodavanje, uređivanje, brisanje. */
new class extends Component {
    /** item | case | project */
    #[Locked]
    public string $ownerType;

    #[Locked]
    public int $ownerId;

    public array $form = ['url' => '', 'title' => ''];

    public ?int $editingId = null;

    public array $editForm = ['url' => '', 'title' => ''];

    public bool $adding = false;

    public function mount(string $ownerType, int $ownerId): void
    {
        $this->ownerType = $ownerType;
        $this->ownerId = $ownerId;
        abort_if($this->owner() === null, 404);
        Gate::authorize('view', Project::owning($this->owner()));
    }

    #[Computed]
    public function owner(): ?Model
    {
        return match ($this->ownerType) {
            'item' => AdministrativeCaseItem::find($this->ownerId),
            'case' => AdministrativeCase::find($this->ownerId),
            'project' => Project::find($this->ownerId),
            default => null,
        };
    }

    #[Computed]
    public function canManage(): bool
    {
        return LinkService::canManage(auth()->user(), $this->owner());
    }

    #[Computed]
    public function links(): Collection
    {
        return $this->owner()->links()->latest()->latest('id')->get();
    }

    public function add(LinkService $service): void
    {
        $this->validate(['form.url' => LinkService::rules()['url'], 'form.title' => LinkService::rules()['title']], LinkService::messages('form.'));
        $service->create($this->owner(), auth()->user(), $this->form);
        $this->reset('form', 'adding');
        unset($this->links);
        Flux::toast('Link je dodan.', variant: 'success', duration: 1500);
    }

    public function edit(int $id): void
    {
        $link = $this->findLink($id);
        $this->editingId = $link->id;
        $this->editForm = ['url' => $link->url, 'title' => $link->title ?? ''];
        $this->resetValidation();
    }

    public function update(LinkService $service): void
    {
        $this->validate(['editForm.url' => LinkService::rules()['url'], 'editForm.title' => LinkService::rules()['title']], LinkService::messages('editForm.'));
        $service->update($this->findLink($this->editingId), auth()->user(), $this->editForm);
        $this->reset('editingId', 'editForm');
        unset($this->links);
    }

    public function delete(int $id, LinkService $service): void
    {
        $service->delete($this->findLink($id), auth()->user());
        unset($this->links);
        Flux::toast('Link je obrisan.');
    }

    private function findLink(?int $id): Link
    {
        return $this->owner()->links()->findOrFail($id);
    }
}; ?>

<div class="space-y-2">
    <ul class="space-y-1">
        @forelse($this->links as $link)
            <li class="group flex items-center gap-2 rounded-md px-1 py-1 hover:bg-zinc-50 dark:hover:bg-zinc-800/60" wire:key="link-{{ $link->id }}">
                @if($editingId === $link->id)
                    <form wire:submit="update" class="flex flex-1 flex-col gap-2 sm:flex-row">
                        <div class="flex-1"><flux:input wire:model="editForm.url" size="sm" aria-label="URL" /><flux:error name="editForm.url" /></div>
                        <flux:input wire:model="editForm.title" size="sm" placeholder="Naslov (opciono)" aria-label="Naslov" class="sm:max-w-48" />
                        <div class="flex gap-1"><flux:button type="submit" size="sm" variant="primary">Sačuvaj</flux:button><flux:button type="button" size="sm" variant="ghost" wire:click="$set('editingId', null)">Odustani</flux:button></div>
                    </form>
                @else
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-500 dark:bg-zinc-800"><flux:icon.globe-alt variant="micro" class="size-4" /></span>
                    <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer" class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium hover:text-blue-600">{{ $link->displayTitle() }}</span>
                        @if(filled($link->title))<span class="block truncate text-xs text-zinc-500">{{ $link->domain() }}</span>@endif
                    </a>
                    @if($this->canManage)
                        <button type="button" wire:click="edit({{ $link->id }})" class="pf-icon-button opacity-0 focus:opacity-100 group-hover:opacity-100" aria-label="Uredi link {{ $link->displayTitle() }}"><flux:icon.pencil variant="micro" class="size-4" /></button>
                        <button type="button" wire:click="delete({{ $link->id }})" wire:confirm="Obrisati link?" class="pf-icon-button opacity-0 hover:text-red-600 focus:opacity-100 group-hover:opacity-100" aria-label="Obriši link {{ $link->displayTitle() }}"><flux:icon.trash variant="micro" class="size-4" /></button>
                    @endif
                @endif
            </li>
        @empty
            <li class="text-sm text-zinc-400">Nema linkova.</li>
        @endforelse
    </ul>

    @if($this->canManage)
        @if($adding)
            <form wire:submit="add" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                <div class="flex-1"><flux:input wire:model="form.url" size="sm" placeholder="https://…" aria-label="URL" autofocus /><flux:error name="form.url" /></div>
                <flux:input wire:model="form.title" size="sm" placeholder="Naslov (opciono)" aria-label="Naslov" class="sm:max-w-48" />
                <div class="flex gap-1"><flux:button type="submit" size="sm" variant="primary">Dodaj</flux:button><flux:button type="button" size="sm" variant="ghost" wire:click="$set('adding', false)">Odustani</flux:button></div>
            </form>
        @else
            <button type="button" wire:click="$set('adding', true)" class="text-sm text-blue-600 hover:underline">+ Dodaj link</button>
        @endif
    @endif
</div>
