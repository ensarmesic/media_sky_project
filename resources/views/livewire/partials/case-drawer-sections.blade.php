{{-- Sekcije detalja postupka: linkovi te komentari i aktivnost. --}}
<section aria-labelledby="case-links-heading">
    <h3 id="case-links-heading" class="mb-2 text-sm font-medium text-zinc-500">Linkovi</h3>
    <livewire:links owner-type="case" :owner-id="$case->id" wire:key="case-links-{{ $case->id }}" />
</section>

<section aria-labelledby="case-comments-heading">
    <h3 id="case-comments-heading" class="mb-2 text-sm font-medium text-zinc-500">Komentari i aktivnost</h3>
    <livewire:comments owner-type="case" :owner-id="$case->id" wire:key="case-comments-{{ $case->id }}" />
</section>
