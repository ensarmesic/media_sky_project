<?php

use App\Enums\GrammaticalGender;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Korisnici')] class extends Component {
    public ?int $editingId = null;
    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $role = 'team_member';
    public string $grammatical_gender = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->isAdministrator(), 403);
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);
        $this->editingId = $user->id; $this->name = $user->name; $this->username = $user->username;
        $this->email = (string) $user->email; $this->role = $user->role->value; $this->grammatical_gender = (string) $user->grammatical_gender?->value; $this->password = ''; $this->password_confirmation = '';
    }

    public function save(): void
    {
        $user = $this->editingId ? User::findOrFail($this->editingId) : new User;
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash:ascii', Rule::unique('users', 'username')->ignore($user)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'grammatical_gender' => ['nullable', Rule::enum(GrammaticalGender::class)],
            'password' => [$user->exists ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ]);
        if ($user->is(auth()->user()) && $data['role'] !== UserRole::Administrator->value) {
            $this->addError('role', 'Ne možete ukloniti vlastita administratorska prava.'); return;
        }
        if ($data['password'] === '') unset($data['password']);
        $data['grammatical_gender'] = $data['grammatical_gender'] ?: null;
        $user->fill($data)->save();
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'username', 'email', 'grammatical_gender', 'password', 'password_confirmation']); $this->role = UserRole::TeamMember->value;
    }

    public function with(): array
    {
        return ['users' => User::orderBy('name')->get()];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')
    <x-pages::settings.layout heading="Korisnici" subheading="Administrativno kreiranje korisnika i dodjela uloga.">
        <form wire:submit="save" class="my-6 space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="name" label="Ime i prezime" required/><flux:input wire:model="username" label="Korisničko ime" required/><flux:input wire:model="email" type="email" label="E-mail (opcionalno)"/><flux:select wire:model="role" label="Uloga">@foreach(UserRole::cases() as $item)<option value="{{ $item->value }}">{{ $item->label() }}</option>@endforeach</flux:select><flux:select wire:model="grammatical_gender" label="Oblik obraćanja"><option value="">Nije odabrano</option>@foreach(GrammaticalGender::cases() as $gender)<option value="{{ $gender->value }}">{{ $gender->label() }}</option>@endforeach</flux:select><flux:input wire:model="password" type="password" label="{{ $editingId ? 'Nova lozinka (opcionalno)' : 'Lozinka' }}"/><flux:input wire:model="password_confirmation" type="password" label="Potvrda lozinke"/></div>
            <div class="flex gap-2"><flux:button type="submit" variant="primary">{{ $editingId ? 'Sačuvaj korisnika' : 'Kreiraj korisnika' }}</flux:button>@if($editingId)<flux:button type="button" wire:click="resetForm">Odustani</flux:button>@endif</div>
        </form>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">@foreach($users as $item)<div class="flex items-center justify-between gap-4 py-4"><div><div class="font-medium">{{ $item->name }}</div><div class="text-sm text-zinc-500">{{ $item->username }} · {{ $item->role->label() }}{{ $item->email ? ' · '.$item->email : '' }}</div></div><flux:button wire:click="edit({{ $item->id }})" size="sm">Uredi</flux:button></div>@endforeach</div>
    </x-pages::settings.layout>
</section>
