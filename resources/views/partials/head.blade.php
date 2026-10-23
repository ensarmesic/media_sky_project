<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/images/media-sky-icon.png" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@if($topBannerOffset = (int) config('app.top_banner_offset'))
    {{-- Server (npr. staging) iznad stranice ubacuje traku fiksne visine; sadržaj se pomjera ispod nje. --}}
    {{-- Visina se mjeri u browseru (na mobitelu se traka prelomi u dva reda); ovaj broj je početna vrijednost. --}}
    <style>
        :root { --pf-top-banner: {{ $topBannerOffset }}px; }
        body { padding-top: var(--pf-top-banner); }
        [data-flux-sidebar][data-flux-sidebar-on-mobile] { top: var(--pf-top-banner) !important; min-height: calc(100dvh - var(--pf-top-banner)) !important; max-height: calc(100dvh - var(--pf-top-banner)) !important; }
    </style>
    <script>
        (() => {
            const fit = () => {
                const banner = [...document.body.children].find((el) => el.style.position === 'fixed' && el.style.top === '0px');
                if (banner) document.documentElement.style.setProperty('--pf-top-banner', banner.offsetHeight + 'px');
            };
            document.addEventListener('DOMContentLoaded', fit);
            document.addEventListener('livewire:navigated', fit);
            window.addEventListener('resize', fit);
        })();
    </script>
@endif

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
