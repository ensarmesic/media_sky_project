<?php

use App\Models\CaseStatus;
use App\Models\CaseType;
use App\Models\Institution;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Šifrarnici predmeta')] class extends Component {
    public ?int $institutionId = null;
    public string $institutionName = '';
    public string $institutionType = '';
    public string $institutionAddress = '';
    public string $institutionContact = '';
    public string $institutionEmail = '';
    public string $institutionPhone = '';
    public string $institutionNotes = '';

    public ?int $typeId = null;
    public string $typeName = '';
    public bool $typeActive = true;

    public ?int $statusId = null;
    public string $statusName = '';
    public string $statusColor = 'zinc';
    public bool $statusActive = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
    }

    public function editInstitution(int $id): void
    {
        $item = Institution::findOrFail($id);
        $this->institutionId = $item->id;
        foreach (['name', 'type', 'address', 'contact_person', 'email', 'phone', 'notes'] as $field) {
            $property = 'institution'.match ($field) {
                'contact_person' => 'Contact', default => str($field)->studly(),
            };
            $this->{$property} = (string) ($item->{$field} ?? '');
        }
    }

    public function saveInstitution(): void
    {
        $data = $this->validate([
            'institutionName' => ['required', 'string', 'max:255'],
            'institutionType' => ['nullable', 'string', 'max:100'],
            'institutionAddress' => ['nullable', 'string', 'max:500'],
            'institutionContact' => ['nullable', 'string', 'max:255'],
            'institutionEmail' => ['nullable', 'email', 'max:255'],
            'institutionPhone' => ['nullable', 'string', 'max:50'],
            'institutionNotes' => ['nullable', 'string', 'max:2000'],
        ]);
        Institution::updateOrCreate(['id' => $this->institutionId], [
            'name' => $data['institutionName'], 'type' => $data['institutionType'], 'address' => $data['institutionAddress'],
            'contact_person' => $data['institutionContact'], 'email' => $data['institutionEmail'],
            'phone' => $data['institutionPhone'], 'notes' => $data['institutionNotes'],
        ]);
        $this->resetInstitution();
    }

    public function resetInstitution(): void
    {
        $this->reset(['institutionId', 'institutionName', 'institutionType', 'institutionAddress', 'institutionContact', 'institutionEmail', 'institutionPhone', 'institutionNotes']);
    }

    public function editType(int $id): void
    {
        $item = CaseType::findOrFail($id);
        $this->typeId = $item->id; $this->typeName = $item->name; $this->typeActive = $item->is_active;
    }

    public function saveType(): void
    {
        $item = $this->typeId ? CaseType::findOrFail($this->typeId) : new CaseType;
        $data = $this->validate(['typeName' => ['required', 'string', 'max:100', Rule::unique('case_types', 'name')->ignore($item)], 'typeActive' => ['boolean']]);
        $item->fill(['name' => $data['typeName'], 'is_active' => $data['typeActive']])->save();
        $this->reset(['typeId', 'typeName']); $this->typeActive = true;
    }

    public function editStatus(int $id): void
    {
        $item = CaseStatus::findOrFail($id);
        $this->statusId = $item->id; $this->statusName = $item->name; $this->statusColor = $item->color; $this->statusActive = $item->is_active;
    }

    public function saveStatus(): void
    {
        $item = CaseStatus::findOrFail($this->statusId);
        $data = $this->validate(['statusName' => ['required', 'string', 'max:100', Rule::unique('case_statuses', 'name')->ignore($item)], 'statusColor' => ['required', Rule::in(['zinc', 'blue', 'amber', 'red', 'green', 'violet'])], 'statusActive' => ['boolean']]);
        $item->update(['name' => $data['statusName'], 'color' => $data['statusColor'], 'is_active' => $data['statusActive']]);
        $this->reset(['statusId', 'statusName']); $this->statusColor = 'zinc'; $this->statusActive = true;
    }

    public function with(): array
    {
        return ['institutions' => Institution::orderBy('name')->get(), 'types' => CaseType::orderBy('name')->get(), 'statuses' => CaseStatus::orderBy('position')->get()];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')
    <x-pages::settings.layout heading="Šifrarnici predmeta" subheading="Institucije, vrste i statusi administrativnih postupaka.">
        <div class="my-6 space-y-10">
            <section><flux:heading>Institucije</flux:heading><form wire:submit="saveInstitution" class="mt-3 grid gap-3"><div class="grid gap-3 sm:grid-cols-2"><flux:input wire:model="institutionName" label="Naziv" required/><flux:input wire:model="institutionType" label="Vrsta organizacije"/><flux:input wire:model="institutionAddress" label="Adresa"/><flux:input wire:model="institutionContact" label="Kontakt osoba"/><flux:input wire:model="institutionEmail" type="email" label="E-mail"/><flux:input wire:model="institutionPhone" label="Telefon"/></div><flux:textarea wire:model="institutionNotes" label="Napomene" rows="2"/><div class="flex gap-2"><flux:button type="submit" variant="primary" size="sm">{{ $institutionId ? 'Sačuvaj instituciju' : 'Dodaj instituciju' }}</flux:button>@if($institutionId)<flux:button type="button" wire:click="resetInstitution" size="sm">Odustani</flux:button>@endif</div></form><div class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">@foreach($institutions as $item)<div class="flex items-center justify-between gap-4 py-3 text-sm"><div><div class="font-medium">{{ $item->name }}</div><div class="text-zinc-500">{{ $item->type ?: 'Bez navedene vrste' }}{{ $item->contact_person ? ' · '.$item->contact_person : '' }}{{ $item->phone ? ' · '.$item->phone : '' }}</div></div><flux:button wire:click="editInstitution({{ $item->id }})" size="sm">Uredi</flux:button></div>@endforeach</div></section>
            <section><flux:heading>Vrste predmeta</flux:heading><form wire:submit="saveType" class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto_auto]"><flux:input wire:model="typeName" placeholder="Naziv vrste"/><flux:checkbox wire:model="typeActive" label="Aktivna"/><flux:button type="submit">{{ $typeId ? 'Sačuvaj' : 'Dodaj' }}</flux:button></form><div class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800">@foreach($types as $item)<div class="flex items-center justify-between py-3 text-sm"><span>{{ $item->name }} · {{ $item->is_active ? 'Aktivna' : 'Neaktivna' }}</span><flux:button wire:click="editType({{ $item->id }})" size="sm">Uredi</flux:button></div>@endforeach</div></section>
            <section><flux:heading>Statusi predmeta</flux:heading><flux:text class="mt-1">Sistemske oznake ostaju nepromijenjene radi automatizacije.</flux:text>@if($statusId)<form wire:submit="saveStatus" class="mt-3 grid gap-3 sm:grid-cols-[1fr_10rem_auto_auto]"><flux:input wire:model="statusName" label="Naziv"/><flux:select wire:model="statusColor" label="Boja">@foreach(['zinc','blue','amber','red','green','violet'] as $color)<option value="{{ $color }}">{{ ucfirst($color) }}</option>@endforeach</flux:select><flux:checkbox wire:model="statusActive" label="Aktivan"/><flux:button type="submit">Sačuvaj</flux:button></form>@endif<div class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800">@foreach($statuses as $item)<div class="flex items-center justify-between py-3 text-sm"><span>{{ $item->name }} · {{ $item->system_key }} · {{ $item->is_active ? 'Aktivan' : 'Neaktivan' }}</span><flux:button wire:click="editStatus({{ $item->id }})" size="sm">Uredi</flux:button></div>@endforeach</div></section>
        </div>
    </x-pages::settings.layout>
</section>
