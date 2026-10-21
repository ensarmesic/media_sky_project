{{-- Sekcije detalja stavke: linkovi te komentari i aktivnost. --}}
<section id="stavka-sekcija-linkovi" class="scroll-mt-16" aria-labelledby="links-heading-{{ $task->id }}">
    <h3 id="links-heading-{{ $task->id }}" class="mb-2 text-sm font-medium text-zinc-500">Linkovi</h3>
    <livewire:links owner-type="item" :owner-id="$task->id" wire:key="item-links-{{ $task->id }}" />
</section>

<section id="stavka-sekcija-komentari" class="scroll-mt-16" aria-labelledby="comments-heading-{{ $task->id }}">
    <h3 id="comments-heading-{{ $task->id }}" class="mb-2 text-sm font-medium text-zinc-500">Komentari i aktivnost</h3>
    <livewire:comments owner-type="item" :owner-id="$task->id" wire:key="item-comments-{{ $task->id }}" />
</section>
