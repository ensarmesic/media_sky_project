<?php

use App\Concerns\ProfileValidationRules;
use App\Enums\GrammaticalGender;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $username = '';
    public string $grammatical_gender = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->username = Auth::user()->username;
        $this->grammatical_gender = (string) Auth::user()->grammatical_gender?->value;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            ...$this->profileRules($user->id),
            'grammatical_gender' => ['nullable', Rule::enum(GrammaticalGender::class)],
        ]);
        $validated['grammatical_gender'] = $validated['grammatical_gender'] ?: null;

        $user->fill($validated);

        $user->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return true;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" subheading="Ažurirajte ime i korisničko ime">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <flux:input wire:model="username" label="Korisničko ime" type="text" required autocomplete="username" />

            <flux:select wire:model="grammatical_gender" label="Oblik obraćanja" description="Koristi se u historiji promjena, npr. „Ensar M. je promijenio status…“.">
                <option value="">Nije odabrano (je promijenio/la)</option>
                @foreach(GrammaticalGender::cases() as $gender)<option value="{{ $gender->value }}">{{ $gender->label() }}</option>@endforeach
            </flux:select>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>
</section>
