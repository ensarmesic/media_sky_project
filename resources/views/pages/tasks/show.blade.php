<?php

use App\Models\AdministrativeCaseItem;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Direktan link na zadatak: /zadaci/{id}. */
new #[Title('Zadatak')] class extends Component {
    public AdministrativeCaseItem $item;

    public function mount(AdministrativeCaseItem $item): void
    {
        abort_unless($item->is_active, 404);
        Gate::authorize('view', $item);
        $this->item = $item->load('administrativeCase.project');
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>Projekti</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('projects.show', $item->administrativeCase->project)" wire:navigate>{{ $item->administrativeCase->project->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $item->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <livewire:task-drawer :page-task-id="$item->id" />
</div>
