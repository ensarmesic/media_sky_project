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

if (! function_exists('password_rules_string')) {
    /**
     * Vrijednost HTML atributa passwordrules (pravila lozinke za password managere) iz Password::defaults().
     * Zamjena za Password::toPasswordRulesString(), koje postoji tek od Laravela 12.
     */
    function password_rules_string(): string
    {
        $rule = Illuminate\Validation\Rules\Password::defaults();
        $read = Closure::bind(fn (): array => [$this->min, $this->max, $this->mixedCase, $this->letters, $this->numbers, $this->symbols], $rule, $rule::class);
        [$min, $max, $mixedCase, $letters, $numbers, $symbols] = $read();

        $parts = ['minlength: '.max(1, (int) $min)];
        if ($max) {
            $parts[] = 'maxlength: '.(int) $max;
        }
        if ($mixedCase) {
            $parts[] = 'required: lower';
            $parts[] = 'required: upper';
        } elseif ($letters) {
            $parts[] = 'required: lower, upper';
        }
        if ($numbers) {
            $parts[] = 'required: digit';
        }
        if ($symbols) {
            $parts[] = 'required: special';
        }

        return implode('; ', $parts).';';
    }
}
