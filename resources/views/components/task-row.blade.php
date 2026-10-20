@props(['item', 'showCase' => false])

{{-- Jedan red zadatka (lista na /projekti, projekat, moji zadaci). Klik otvara detalj zadatka. --}}
@php($isOverdue = $item->isOverdue())
<a href="{{ $item->url() }}"
    x-on:click="if (! $event.metaKey && ! $event.ctrlKey && ! $event.shiftKey && window.Livewire?.getByName?.('task-drawer')?.length) { $event.preventDefault(); $event.stopImmediatePropagation(); $dispatch('open-task', { id: {{ $item->id }} }) }"
    {{ $attributes->class('flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-zinc-50 focus:outline-none focus-visible:bg-zinc-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 dark:hover:bg-zinc-800/60 dark:focus-visible:bg-zinc-800/60') }}>
    <span class="min-w-0 flex-1">
        <span class="block truncate font-medium text-zinc-800 dark:text-zinc-100">{{ $item->name }}</span>
        @if($showCase)<span class="block truncate text-xs text-zinc-500">{{ $item->administrativeCase->name }}</span>@endif
    </span>
    @if(($item->comments_count ?? 0) > 0)
        <span class="flex shrink-0 items-center gap-0.5 text-xs text-zinc-400" title="Komentari"><flux:icon.chat-bubble-left variant="micro" class="size-3.5" />{{ $item->comments_count }}</span>
    @endif
    @if(($item->attachments_count ?? 0) > 0)
        <span class="flex shrink-0 items-center gap-0.5 text-xs text-zinc-400" title="Prilozi"><flux:icon.paper-clip variant="micro" class="size-3.5" />{{ $item->attachments_count }}</span>
    @endif
    @if($item->due_date)
        <span @class(['shrink-0 text-xs tabular-nums', 'font-semibold text-red-600 dark:text-red-400' => $isOverdue, 'text-zinc-500' => ! $isOverdue]) title="{{ $isOverdue ? 'Kasni — rok' : 'Rok' }} {{ $item->due_date->format('d.m.Y.') }}">{{ $item->due_date->format('d.m.') }}</span>
    @endif
    <flux:badge :color="$item->status->color()" size="sm" class="shrink-0">{{ $item->status->label() }}</flux:badge>
    @if($item->responsible)
        <flux:avatar size="xs" :name="$item->responsible->name" :initials="$item->responsible->initials()" :tooltip="$item->responsible->name" class="shrink-0" />
    @else
        <span class="flex size-6 shrink-0 items-center justify-center rounded-full border border-dashed border-zinc-300 text-zinc-300 dark:border-zinc-600" title="Nije dodijeljeno"><flux:icon.user variant="micro" class="size-3" /></span>
    @endif
</a>
