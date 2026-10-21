{{--
    Logo Media Sky za svijetlu i tamnu temu. Flux postavlja klasu "dark" na <html> sinhronim
    skriptom u <head> (prije iscrtavanja), pa izbor preko dark: varijante ne treperi.
    Dok ne postoji public/img/logo-dark.png, tamna tema koristi svijetli logo s filterom (.logo-dark-fallback).
--}}
@php($hasDarkLogo = file_exists(public_path('img/logo-dark.png')))
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0']) }}>
    <img src="{{ asset('img/logo-light.png') }}" alt="Media Sky Telekomunikacije" width="621" height="176" class="h-full w-auto dark:hidden">
    <img src="{{ asset($hasDarkLogo ? 'img/logo-dark.png' : 'img/logo-light.png') }}" alt="Media Sky Telekomunikacije" width="621" height="176" @class(['hidden h-full w-auto dark:block', 'logo-dark-fallback' => ! $hasDarkLogo])>
</span>
