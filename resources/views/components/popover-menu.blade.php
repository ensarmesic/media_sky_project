@props([
    // Poravnanje menija u odnosu na dugme: start | end
    'align' => 'end',
    'width' => 'w-52',
])

{{--
    Padajući meni koji se prikazuje iznad svega (teleport u <body>, fixed pozicija uz dugme preko x-anchor),
    pa ga tabele s horizontalnim skrolom ne odsijecaju; uz ivicu ekrana se sam okreće ili pomjera.
    Upotreba: <x-popover-menu><x-slot:trigger><button …>⋯</button></x-slot:trigger> …stavke… </x-popover-menu>
--}}
<div x-data="{ open: false }" {{ $attributes->merge(['class' => 'inline-block text-left']) }} x-on:keydown.escape.window="open = false">
    <div x-ref="trigger" x-on:click="open = ! open" class="contents">{{ $trigger }}</div>
    <template x-teleport="body">
        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
            x-anchor.fixed.bottom-{{ $align === 'start' ? 'start' : 'end' }}.offset.4="$refs.trigger.firstElementChild ?? $refs.trigger"
            x-on:click.outside="if (! $refs.trigger.contains($event.target)) open = false"
            x-on:click="open = false"
            role="menu" class="pf-popover {{ $width }}">
            {{ $slot }}
        </div>
    </template>
</div>
