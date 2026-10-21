<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pomoć')] class extends Component {}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Pomoć</flux:heading>
        <flux:subheading class="mt-1">Kratki vodič kroz {{ config('app.name') }}</flux:subheading>
    </div>

    @foreach([
        ['folder', 'Projekat', 'Projekat okuplja sve postupke, dokumente i članove. Status projekta i ručnu procjenu stanja mijenja voditelj projekta; administrativni napredak se računa automatski iz završenih stavki.'],
        ['clipboard-document-list', 'Postupak', 'Postupak je administrativni proces prema jednoj instituciji (npr. urbanistička saglasnost). Njegov status se računa iz stavki: sve završene → Završeno, neka čeka odgovor → Čeka odgovor, neka je u radu → U toku.'],
        ['check-circle', 'Stavka', 'Stavka je konkretan korak u postupku (npr. Saglasnost EPBiH). Ima status, rok, datum predaje i odgovora te odgovornu osobu. Klik na stavku otvara detalje u bočnom panelu.'],
        ['exclamation-triangle', 'Zahtijeva pažnju', 'Stavke koje kasne, čekaju odgovor duže od '.config('projectflow.attention.waiting_days').' dana ili imaju rok u narednih '.config('projectflow.attention.due_within_days').' dana automatski se prikazuju u desnoj koloni projekta.'],
        ['magnifying-glass', 'Pretraga', 'Pritisnite Ctrl + K (ili Cmd + K) bilo gdje u aplikaciji da pretražite projekte, postupke, stavke i dokumente.'],
        ['bell', 'Obavijesti', 'Obavijest dobijate kad vam se dodijeli stavka, kad vas neko spomene (@korisnicko.ime), kad se promijeni status vaše stavke i kad rok ističe. Postavke obavijesti su u Postavkama.'],
    ] as [$icon, $title, $text])
        <section class="flex gap-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-300"><flux:icon :name="$icon" class="size-5" /></div>
            <div>
                <flux:heading>{{ $title }}</flux:heading>
                <flux:text class="mt-1">{{ $text }}</flux:text>
            </div>
        </section>
    @endforeach
</div>
