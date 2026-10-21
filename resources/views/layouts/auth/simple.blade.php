<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="{{ route('home') }}" class="mb-2 flex flex-col items-center gap-3 font-medium" wire:navigate>
                    <span class="rounded-xl bg-white px-4 py-3">
                        <img src="{{ asset('images/media-sky-logo.png') }}" alt="Media Sky Telekomunikacije" width="621" height="176" class="h-14 w-auto">
                    </span>
                    <span class="text-sm font-semibold uppercase tracking-[0.2em] text-zinc-500 dark:text-zinc-400">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
