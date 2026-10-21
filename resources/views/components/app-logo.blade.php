@props([
    'sidebar' => false,
])

{{-- Logo Media Sky + oznaka "Project". U tamnoj temi logo stoji na svijetloj podlozi da plava i zelena ostanu čitljive. --}}
<a {{ $attributes->merge(['class' => 'group flex min-w-0 items-center gap-2 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500']) }} aria-label="{{ config('app.name') }}">
    <span class="flex shrink-0 items-center rounded-md dark:bg-white dark:px-1.5 dark:py-1">
        <img src="{{ asset('images/media-sky-logo.png') }}" alt="Media Sky Telekomunikacije" width="621" height="176" @class(['w-auto', 'h-9' => $sidebar, 'h-8' => ! $sidebar])>
    </span>
    <span class="rounded bg-blue-600 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-white">Project</span>
</a>
