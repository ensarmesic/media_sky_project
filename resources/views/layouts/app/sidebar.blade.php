<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>@include('partials.head')</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
@php($user = auth()->user())
@php($myOpenItems = $user->assignedItems()->open()->whereHas('administrativeCase', fn ($query) => $query->where('is_archived', false))->count())
@php($statusCounts = App\Models\Project::visibleTo($user)->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'))
@php($activeStatusFilter = request()->routeIs('projects.index') ? request('status', '') : null)

<flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <flux:sidebar.header>
        <x-app-logo :sidebar="true" href="{{ route('projects.index') }}" wire:navigate />
        <flux:sidebar.collapse class="lg:hidden" />
    </flux:sidebar.header>

    <flux:sidebar.nav>
        <flux:sidebar.item icon="folder" :href="route('projects.index')" :current="request()->routeIs('projects.*', 'tasks.*') && ! $activeStatusFilter" wire:navigate>Projekti</flux:sidebar.item>
        <flux:sidebar.item icon="clipboard-document-check" :href="route('my-tasks.index')" :current="request()->routeIs('my-tasks.*')" :badge="$myOpenItems ?: null" wire:navigate>Moje stavke</flux:sidebar.item>
        <flux:sidebar.item icon="building-office-2" :href="route('institutions.index')" :current="request()->routeIs('institutions.*')" wire:navigate>Institucije</flux:sidebar.item>
        <flux:sidebar.item icon="cog-6-tooth" :href="route('profile.edit')" :current="request()->routeIs('profile.*', 'appearance.*', 'security.*', 'categories.*', 'case-catalogs.*', 'users.*', 'notification-settings.*', 'system-health.*')" wire:navigate>Postavke</flux:sidebar.item>
    </flux:sidebar.nav>

    {{-- Filteri projekata po statusu (vežu se na listu /projekti) --}}
    <flux:sidebar.nav>
        <div class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400">Projekti</div>
        @foreach([
            ['', 'Svi projekti', 'squares-2x2', $statusCounts->except('archived')->sum()],
            ['in_progress', 'U toku', 'arrow-path', $statusCounts->get('in_progress', 0)],
            ['on_hold', 'Na čekanju', 'pause-circle', $statusCounts->get('on_hold', 0) + $statusCounts->get('suspended', 0)],
            ['completed', 'Završeni', 'check-circle', $statusCounts->get('completed', 0)],
            ['archived', 'Arhivirani', 'archive-box', $statusCounts->get('archived', 0)],
        ] as [$statusKey, $statusLabel, $statusIcon, $statusCount])
            <flux:sidebar.item :icon="$statusIcon" :href="route('projects.index', $statusKey !== '' ? ['status' => $statusKey] : [])" :current="$activeStatusFilter === $statusKey && $statusKey !== ''" :badge="$statusCount" badge:color="zinc" wire:navigate>{{ $statusLabel }}</flux:sidebar.item>
        @endforeach
    </flux:sidebar.nav>

    <flux:spacer />

    <flux:sidebar.nav>
        <flux:sidebar.item icon="question-mark-circle" :href="route('help')" :current="request()->routeIs('help')" wire:navigate>Pomoć</flux:sidebar.item>
    </flux:sidebar.nav>
</flux:sidebar>

{{-- Gornja traka: pretraga (Ctrl+K), obavijesti, korisnik --}}
<flux:header sticky class="border-b border-zinc-200 bg-white/90 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/90">
    <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" aria-label="Meni" />

    <button type="button" x-data x-on:click="$dispatch('open-search')"
        class="ml-1 flex h-9 w-full max-w-sm items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 text-sm text-zinc-400 hover:border-zinc-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-800 lg:ml-0"
        aria-label="Pretraga (Ctrl+K)">
        <flux:icon.magnifying-glass class="size-4" />
        <span class="flex-1 truncate text-left">Pretraži projekte…</span>
        <kbd class="hidden rounded border border-zinc-200 bg-white px-1.5 py-0.5 font-sans text-[11px] text-zinc-400 sm:inline dark:border-zinc-600 dark:bg-zinc-900">Ctrl + K</kbd>
    </button>

    <flux:spacer />

    <livewire:notification-bell />

    <flux:dropdown position="bottom" align="end">
        <button type="button" class="flex items-center gap-2 rounded-lg p-1 pr-2 hover:bg-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:hover:bg-zinc-800" data-test="sidebar-menu-button">
            <flux:avatar size="sm" :name="$user->name" :initials="$user->initials()" class="bg-zinc-900! text-white! dark:bg-zinc-200! dark:text-zinc-900!" />
            <span class="hidden text-sm font-medium sm:inline">{{ $user->name }}</span>
            <flux:icon.chevron-down variant="micro" class="size-4 text-zinc-400" />
        </button>
        <flux:menu>
            <div class="px-2 py-1.5 text-sm">
                <div class="font-medium">{{ $user->name }}</div>
                <div class="text-xs text-zinc-500">{{ $user->username }}</div>
            </div>
            <flux:menu.separator />
            <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>Postavke</flux:menu.item>
            <flux:menu.item x-data x-on:click="$flux.appearance = $flux.dark ? 'light' : 'dark'" icon="moon">Promijeni temu</flux:menu.item>
            <flux:menu.separator />
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">Odjava</flux:menu.item>
            </form>
        </flux:menu>
    </flux:dropdown>
</flux:header>

{{ $slot }}

<livewire:global-search />
{{-- Detalj stavke kao bočni panel dostupan na svim stranicama (event "open-task"). --}}
@unless(request()->routeIs('tasks.show'))<livewire:task-drawer />@endunless
<livewire:case-drawer />
@persist('toast')<flux:toast.group><flux:toast /></flux:toast.group>@endpersist
@fluxScripts
</body></html>
