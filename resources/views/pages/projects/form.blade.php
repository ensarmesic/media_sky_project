<?php

use App\Enums\ProjectStatus;
use App\Livewire\Forms\ProjectForm;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projekat')] class extends Component {
    public ProjectForm $form;

    public function mount(?Project $project = null): void
    {
        if ($project?->exists) { Gate::authorize('update', $project); $this->form->setProject($project); }
        else Gate::authorize('create', Project::class);
    }

    public function save(): void
    {
        Gate::authorize($this->form->project ? 'update' : 'create', $this->form->project ?: Project::class);
        $project = $this->form->save();
        $this->redirectRoute('projects.show', $project, navigate: true);
    }

    public function with(): array
    {
        return ['categories' => ProjectCategory::where('is_active', true)->orderBy('name')->get(), 'users' => User::orderBy('name')->get()];
    }
}; ?>

<div class="mx-auto w-full max-w-4xl">
    <flux:breadcrumbs class="mb-6"><flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projekti</flux:breadcrumbs.item><flux:breadcrumbs.item>{{ $form->project ? 'Uredi projekat' : 'Novi projekat' }}</flux:breadcrumbs.item></flux:breadcrumbs>
    <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
        <div><flux:heading size="xl">{{ $form->project ? 'Uredi projekat' : 'Novi projekat' }}</flux:heading><flux:subheading class="mt-1">Unesite osnovne informacije. Dodatne podatke možete dopuniti kasnije.</flux:subheading></div>
        <div class="grid gap-5 sm:grid-cols-2">
            <flux:input wire:model="form.code" label="Interna oznaka" placeholder="PRJ-2026-001" required />
            <flux:input wire:model="form.name" label="Naziv" required />
            <div class="sm:col-span-2"><flux:textarea wire:model="form.description" label="Opis" rows="3" /></div>
            <flux:select wire:model="form.category_id" label="Kategorija" required><option value="">Odaberite kategoriju</option>@foreach($categories as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</flux:select>
            <flux:input wire:model="form.location" label="Lokacija" />
            <flux:input wire:model="form.start_date" type="date" label="Datum početka" required />
            <flux:input wire:model="form.planned_end_date" type="date" label="Planirani završetak" />
            <flux:select wire:model="form.responsible_user_id" label="Odgovorna osoba" required><option value="">Odaberite osobu</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</flux:select>
            <flux:select wire:model="form.status" label="Status" required>@foreach(ProjectStatus::cases() as $item)<option value="{{ $item->value }}">{{ $item->label() }}</option>@endforeach</flux:select>
            <div class="sm:col-span-2"><label class="mb-2 block text-sm font-medium">Članovi projekta</label><div class="grid gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700 sm:grid-cols-2">@foreach($users as $user)<label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.member_ids" value="{{ $user->id }}" class="rounded">{{ $user->name }}</label>@endforeach</div>@error('form.member_ids.*')<div class="mt-1 text-sm text-red-600">{{ $message }}</div>@enderror</div>
            <div class="sm:col-span-2"><flux:textarea wire:model="form.notes" label="Napomene" rows="3" /></div>
        </div>
        <div class="flex justify-end gap-3"><flux:button :href="$form->project ? route('projects.show', $form->project) : route('projects.index')" wire:navigate>Odustani</flux:button><flux:button type="submit" variant="primary">Sačuvaj projekat</flux:button></div>
    </form>
</div>
