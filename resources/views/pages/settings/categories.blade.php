<?php

use App\Models\ProjectCategory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Kategorije projekata')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';
    public bool $isActive = true;

    public function mount(): void { Gate::authorize('viewAny', ProjectCategory::class); }
    public function edit(int $id): void { $category = ProjectCategory::findOrFail($id); Gate::authorize('update', $category); $this->editingId=$id; $this->name=$category->name; $this->description=$category->description ?? ''; $this->isActive=$category->is_active; }
    public function resetForm(): void { $this->reset(['editingId','name','description']); $this->isActive=true; }
    public function save(): void
    {
        $category = $this->editingId ? ProjectCategory::findOrFail($this->editingId) : new ProjectCategory;
        Gate::authorize($category->exists ? 'update' : 'create', $category->exists ? $category : ProjectCategory::class);
        $data=$this->validate(['name'=>['required','string','max:100',Rule::unique('project_categories')->ignore($category)],'description'=>['nullable','string','max:1000'],'isActive'=>['boolean']]);
        $category->fill(['name'=>$data['name'],'description'=>$data['description'],'is_active'=>$data['isActive']])->save();
        $this->resetForm();
    }
    public function with(): array { return ['categories'=>ProjectCategory::withCount('projects')->orderBy('name')->get()]; }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')
    <x-pages::settings.layout heading="Kategorije projekata" subheading="Upravljajte kategorijama dostupnim pri unosu projekta.">
        @can('create', App\Models\ProjectCategory::class)<form wire:submit="save" class="my-6 space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"><flux:input wire:model="name" label="Naziv" required/><flux:textarea wire:model="description" label="Opis" rows="2"/><flux:checkbox wire:model="isActive" label="Aktivna kategorija"/><div class="flex gap-2"><flux:button type="submit" variant="primary">{{ $editingId ? 'Sačuvaj izmjene' : 'Dodaj kategoriju' }}</flux:button>@if($editingId)<flux:button type="button" wire:click="resetForm">Odustani</flux:button>@endif</div></form>@endcan
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">@forelse($categories as $category)<div class="flex items-center justify-between gap-4 py-4"><div><div class="font-medium">{{ $category->name }} @unless($category->is_active)<flux:badge color="zinc">Neaktivna</flux:badge>@endunless</div><div class="mt-1 text-sm text-zinc-500">{{ $category->description ?: 'Bez opisa' }} · {{ $category->projects_count }} projekata</div></div>@can('update',$category)<flux:button size="sm" wire:click="edit({{ $category->id }})">Uredi</flux:button>@endcan</div>@empty<flux:text class="py-6">Nema kategorija.</flux:text>@endforelse</div>
    </x-pages::settings.layout>
</section>
