@props(['item', 'editable' => true, 'action' => 'changeItemStatus'])

{{-- Status chip stavke; klik otvara izbor statusa (lagan Alpine popover, bez Flux markup-a po redu). --}}
@if($editable)
    <div x-data="{ open: false }" class="relative inline-block" x-on:keydown.escape="open = false">
        <button type="button" x-on:click="open = ! open" class="{{ $item->status->chipClass() }} cursor-pointer" aria-haspopup="true" :aria-expanded="open.toString()" aria-label="Status: {{ $item->status->label() }} — promijeni">{{ $item->status->label() }}</button>
        <div x-show="open" x-cloak x-on:click.outside="open = false" x-on:click="open = false" class="pf-menu left-0 w-44" role="menu">
            @foreach(App\Enums\CaseItemStatus::cases() as $option)
                <button type="button" role="menuitem" wire:click="{{ $action }}({{ $item->id }}, '{{ $option->value }}')" class="pf-menu-item{{ $item->status === $option ? ' font-semibold' : '' }}"><span class="size-2 rounded-full {{ $option->dotClass() }}"></span>{{ $option->label() }}</button>
            @endforeach
        </div>
    </div>
@else
    <span class="{{ $item->status->chipClass() }}">{{ $item->status->label() }}</span>
@endif
