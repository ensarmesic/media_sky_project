@props(['item', 'editable' => true, 'action' => 'changeItemStatus', 'align' => 'left'])

{{-- Status chip stavke; klik otvara izbor statusa (meni iznad tabele, ne odsijeca ga skrol). --}}
@if($editable)
    <x-popover-menu :align="$align === 'right' ? 'end' : 'start'" width="w-48">
        <x-slot:trigger>
            <button type="button" class="{{ $item->status->chipClass() }} cursor-pointer" aria-haspopup="menu" :aria-expanded="open.toString()" aria-label="Status: {{ $item->status->label() }} — promijeni">{{ $item->status->label() }}</button>
        </x-slot:trigger>
        @foreach(App\Enums\CaseItemStatus::cases() as $option)
            <button type="button" role="menuitem" wire:click="{{ $action }}({{ $item->id }}, '{{ $option->value }}')" class="pf-menu-item{{ $item->status === $option ? ' font-semibold' : '' }}"><span class="size-2 shrink-0 rounded-full {{ $option->dotClass() }}"></span>{{ $option->label() }}</button>
        @endforeach
    </x-popover-menu>
@else
    <span class="{{ $item->status->chipClass() }}">{{ $item->status->label() }}</span>
@endif
