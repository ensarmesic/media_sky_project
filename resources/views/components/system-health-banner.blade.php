{{-- Upozorenje adminu kad scheduler ne radi ili se jobovi gomilaju (detalji: Postavke → Stanje sistema). --}}
@if(auth()->user()?->isAdministrator())
    @php($healthWarnings = app(App\Services\SystemHealth::class)->warnings())
    @if($healthWarnings !== [])
        <div class="mx-auto mb-5 flex w-full max-w-350 items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200" role="alert">
            <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-5 shrink-0" />
            <div class="min-w-0 flex-1">
                @foreach($healthWarnings as $warning)<p>{{ $warning }}</p>@endforeach
            </div>
            <a href="{{ route('system-health.index') }}" wire:navigate class="shrink-0 font-medium underline">Stanje sistema</a>
        </div>
    @endif
@endif
