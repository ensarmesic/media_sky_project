<?php

use App\Enums\NotificationKind;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Postavke obavijesti: koje vrste dobijam, u aplikaciji i/ili e-mailom, dnevni sažetak. */
new #[Title('Postavke obavijesti')] class extends Component {
    /** @var array<int, string> Uključene vrste obavijesti */
    public array $kinds = [];

    public bool $database_enabled = true;

    public bool $email_enabled = true;

    public bool $daily_digest = false;

    public int $digest_hour = 7;

    public function mount(): void
    {
        $setting = auth()->user()->notificationSettingFor();
        $muted = $setting->muted_kinds ?? [];
        $this->kinds = collect(NotificationKind::cases())->map->value->reject(fn (string $kind) => in_array($kind, $muted, true))->values()->all();
        $this->database_enabled = $setting->database_enabled;
        $this->email_enabled = $setting->email_enabled;
        $this->daily_digest = $setting->daily_digest;
        $this->digest_hour = $setting->digest_hour;
    }

    public function save(): void
    {
        $this->validate([
            'kinds' => ['array'],
            'kinds.*' => [Rule::enum(NotificationKind::class)],
            'digest_hour' => ['integer', 'between:0,23'],
        ]);

        $muted = collect(NotificationKind::cases())->map->value->diff($this->kinds)->values()->all();
        auth()->user()->notificationSettingFor()->update([
            'muted_kinds' => $muted,
            'database_enabled' => $this->database_enabled,
            'email_enabled' => $this->email_enabled,
            'daily_digest' => $this->daily_digest,
            'digest_hour' => $this->digest_hour,
        ]);

        Flux::toast(variant: 'success', text: 'Postavke obavijesti su sačuvane.');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">Postavke obavijesti</flux:heading>

    <x-pages::settings.layout heading="Obavijesti" subheading="Odaberite o čemu i kako želite biti obaviješteni.">
        <form wire:submit="save" class="space-y-8">
            <fieldset class="space-y-3">
                <legend class="mb-2 text-sm font-semibold">Kako dobijam obavijesti</legend>
                <flux:checkbox wire:model="database_enabled" label="U aplikaciji (zvono)" />
                <flux:checkbox wire:model="email_enabled" label="E-mailom" :description="blank(auth()->user()->email) ? 'Vaš račun nema e-mail adresu, pa se e-mail ne šalje.' : 'Na adresu '.auth()->user()->email" />
            </fieldset>

            <fieldset class="space-y-3">
                <legend class="mb-2 text-sm font-semibold">O čemu me obavještavati</legend>
                @foreach(NotificationKind::cases() as $kind)
                    <flux:checkbox wire:model="kinds" :value="$kind->value" :label="$kind->label()" wire:key="kind-{{ $kind->value }}" />
                @endforeach
            </fieldset>

            <fieldset class="space-y-3">
                <legend class="mb-2 text-sm font-semibold">Dnevni sažetak</legend>
                <flux:checkbox wire:model.live="daily_digest" label="Šalji mi jednom dnevno e-mail s pregledom obaveza" description="Stavke koje kasne, kojima rok ističe i koje predugo čekaju odgovor." />
                @if($daily_digest)
                    <flux:select wire:model="digest_hour" label="Vrijeme slanja" class="max-w-40">
                        @foreach(range(5, 20) as $hour)<option value="{{ $hour }}">{{ sprintf('%02d:00', $hour) }}</option>@endforeach
                    </flux:select>
                @endif
            </fieldset>

            <flux:button type="submit" variant="primary">Sačuvaj</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
