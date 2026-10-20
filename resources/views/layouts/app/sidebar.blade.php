<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>@include('partials.head')</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
<flux:sidebar sticky collapsible="true" class="border-e border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
 <flux:sidebar.header><x-app-logo :sidebar="true" href="{{ route('projects.index') }}" wire:navigate/><flux:sidebar.collapse class="lg:hidden"/></flux:sidebar.header>
 @php($unreadNotifications = auth()->user()->unreadNotifications()->count())
 @php($myOpenTasks = auth()->user()->assignedItems()->where('is_active', true)->whereNotIn('status', ['completed', 'not_applicable'])->whereHas('administrativeCase', fn ($query) => $query->where('is_archived', false))->count())
 <flux:sidebar.nav>
  <flux:sidebar.group class="grid">
   <flux:sidebar.item icon="folder" :href="route('projects.index')" :current="request()->routeIs('projects.*')" wire:navigate>Projekti</flux:sidebar.item>
   <flux:sidebar.item icon="check-circle" :href="route('my-tasks.index')" :current="request()->routeIs('my-tasks.*')" :badge="$myOpenTasks ?: null" wire:navigate>Moji zadaci</flux:sidebar.item>
   <flux:sidebar.item icon="bell" :href="route('notifications.index')" :current="request()->routeIs('notifications.*')" :badge="$unreadNotifications ?: null" badge:color="blue" wire:navigate>Obavijesti</flux:sidebar.item>
  </flux:sidebar.group>
 </flux:sidebar.nav>
 <flux:spacer/>
 <flux:sidebar.nav><flux:sidebar.item icon="cog-6-tooth" :href="route('profile.edit')" :current="request()->routeIs('profile.*','appearance.*','security.*','categories.*','case-catalogs.*','users.*')" wire:navigate>Postavke</flux:sidebar.item></flux:sidebar.nav>
 <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name"/>
</flux:sidebar>
<flux:header class="lg:hidden"><flux:sidebar.toggle icon="bars-2" inset="left"/><flux:spacer/><flux:dropdown position="top" align="end"><flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down"/><flux:menu><flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>Postavke</flux:menu.item><flux:menu.separator/><form method="POST" action="{{ route('logout') }}">@csrf<flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">Odjava</flux:menu.item></form></flux:menu></flux:dropdown></flux:header>
{{ $slot }}
@persist('toast')<flux:toast.group><flux:toast /></flux:toast.group>@endpersist
@fluxScripts
</body></html>
