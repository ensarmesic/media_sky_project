<?php

use App\Services\InstitutionOverview;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Institucije: otvorene stavke po instituciji iz projekata koje korisnik vidi, sortirano po onome što traži pažnju. */
new #[Title('Institucije')] class extends Component {
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @return Collection<int, array{institution: App\Models\Institution, open: int, waiting: int, overdue: int, longest: ?int}> */
    #[Computed]
    public function institutions(): Collection
    {
        return app(InstitutionOverview::class)->summaries(auth()->user(), $this->search);
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">Institucije</flux:heading>
            <flux:subheading class="mt-1">Otvorene stavke po instituciji iz vaših projekata — prvo one koje kasne ili najduže čekaju odgovor.</flux:subheading>
        </div>
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Pretraži institucije…" aria-label="Pretraži institucije" class="sm:max-w-64" clearable />
    </div>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" wire:loading.delay.class="opacity-60">
        <div class="hidden grid-cols-[minmax(0,1fr)_repeat(4,6.5rem)] gap-3 border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 text-xs font-medium text-zinc-500 sm:grid dark:border-zinc-800 dark:bg-zinc-800/50">
            <span>Institucija</span><span class="text-right">Otvorene</span><span class="text-right">Čeka odgovor</span><span class="text-right">Kasni</span><span class="text-right">Najduže čeka</span>
        </div>
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse($this->institutions as $row)
                @php($institution = $row['institution'])
                <li wire:key="institution-{{ $institution->id }}">
                    <a href="{{ route('institutions.show', $institution) }}" wire:navigate class="grid grid-cols-2 gap-x-3 gap-y-1 px-4 py-3 hover:bg-zinc-50 focus:outline-none focus-visible:bg-zinc-50 sm:grid-cols-[minmax(0,1fr)_repeat(4,6.5rem)] sm:items-center dark:hover:bg-zinc-800/50">
                        <span class="col-span-2 min-w-0 sm:col-span-1">
                            <span class="block truncate font-medium">{{ $institution->name }}</span>
                            @if($institution->type)<span class="block truncate text-xs text-zinc-500">{{ $institution->type }}</span>@endif
                        </span>
                        <span class="text-sm tabular-nums sm:text-right"><span class="text-xs text-zinc-500 sm:hidden">Otvorene: </span>{{ $row['open'] }}</span>
                        <span @class(['text-sm tabular-nums sm:text-right', 'font-medium text-orange-600 dark:text-orange-400' => $row['waiting'] > 0])><span class="text-xs text-zinc-500 sm:hidden">Čeka odgovor: </span>{{ $row['waiting'] }}</span>
                        <span @class(['text-sm tabular-nums sm:text-right', 'font-semibold text-red-600 dark:text-red-400' => $row['overdue'] > 0])><span class="text-xs text-zinc-500 sm:hidden">Kasni: </span>{{ $row['overdue'] }}</span>
                        <span class="text-sm tabular-nums sm:text-right"><span class="text-xs text-zinc-500 sm:hidden">Najduže čeka: </span>{{ $row['longest'] !== null ? $row['longest'].' d' : '–' }}</span>
                    </a>
                </li>
            @empty
                <li class="px-6 py-14 text-center">
                    <flux:icon.building-office-2 class="mx-auto size-10 text-zinc-400" />
                    <flux:heading class="mt-3">{{ trim($search) !== '' ? 'Nema institucija za ovu pretragu' : 'Još nema unesenih institucija' }}</flux:heading>
                    <flux:text class="mt-1">Institucije se unose u Postavke → Šifrarnici postupaka, a stavci se dodjeljuju u njenom bočnom panelu.</flux:text>
                </li>
            @endforelse
        </ul>
    </section>
</div>
