<x-layouts::app :title="$title">
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <div><flux:heading size="xl" level="1">{{ $title }}</flux:heading><flux:subheading class="mt-1">{{ $description }}</flux:subheading></div>
        <div class="flex min-h-96 flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 bg-zinc-50/50 px-6 text-center dark:border-zinc-700 dark:bg-zinc-900/40">
            <div class="mb-4 flex size-11 items-center justify-center rounded-xl border border-zinc-200 bg-white text-zinc-500 shadow-sm dark:border-zinc-700 dark:bg-zinc-800"><flux:icon :name="$icon" class="size-5" /></div>
            <flux:heading size="lg">Nema evidentiranih podataka</flux:heading>
            <flux:text class="mt-2 max-w-md">Ovaj radni prostor je pripremljen i bit će aktiviran u odgovarajućoj razvojnoj fazi.</flux:text>
        </div>
    </div>
</x-layouts::app>
