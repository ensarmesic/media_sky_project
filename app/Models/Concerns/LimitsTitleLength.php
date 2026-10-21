<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Naslov zapisa aktivnosti sastavlja se od prefiksa i naziva ("Kreirana stavka: …"), pa može preći
 * 255 znakova kolone. SQLite to tolerira, MySQL (strict) odbija upis — zato se naslov skraćuje.
 */
trait LimitsTitleLength
{
    /** @return Attribute<string, string> */
    protected function title(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strimwidth($value, 0, 255, '…'));
    }
}
