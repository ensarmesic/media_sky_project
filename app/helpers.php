<?php

use Carbon\CarbonImmutable;

if (! function_exists('local_time')) {
    /** Trenutak iz baze (UTC) u lokalnoj zoni korisnika, za prikaz (config app.display_timezone). */
    function local_time(?DateTimeInterface $time): ?CarbonImmutable
    {
        return $time === null ? null : CarbonImmutable::instance($time)->setTimezone(config('app.display_timezone'));
    }
}

if (! function_exists('local_today')) {
    /**
     * Današnji datum po lokalnoj zoni, kao ponoć u UTC — isti oblik kao kolone s datumom (due_date, submitted_at...),
     * pa se s njima smije porediti i upisivati u njih. today() bi u UTC-u kasnio do 2 sata iza ponoći.
     */
    function local_today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(config('app.display_timezone'))->toDateString(), config('app.timezone'));
    }
}
