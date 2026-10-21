<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <x-system-health-banner />
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
