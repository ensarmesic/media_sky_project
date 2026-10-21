@props([
    'sidebar' => false,
])

{{-- Logo Media Sky + oznaka "Project". Verziju za svijetlu/tamnu temu bira <x-brand-logo>. --}}
<a {{ $attributes->merge(['class' => 'group flex min-w-0 items-center gap-2 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500']) }} aria-label="{{ config('app.name') }}">
    <x-brand-logo @class(['h-9' => $sidebar, 'h-8' => ! $sidebar]) />
    <span class="rounded bg-blue-600 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-white">Project</span>
</a>
