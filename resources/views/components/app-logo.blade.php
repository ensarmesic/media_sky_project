@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-blue-600 text-white shadow-sm">
            <x-app-logo-icon class="size-5 text-white" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-blue-600 text-white shadow-sm">
            <x-app-logo-icon class="size-5 text-white" />
        </x-slot>
    </flux:brand>
@endif
