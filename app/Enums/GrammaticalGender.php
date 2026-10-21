<?php

namespace App\Enums;

/** Oblik obraćanja korisnika u rečenicama historije ("je promijenio" / "je promijenila"). */
enum GrammaticalGender: string
{
    case Masculine = 'm';
    case Feminine = 'f';

    public function label(): string
    {
        return match ($this) {
            self::Masculine => 'Muški rod (je promijenio)',
            self::Feminine => 'Ženski rod (je promijenila)',
        };
    }

    /** Glagolski pridjev radni iz muškog oblika: "promijenio" → "promijenila"; bez oblika "promijenio/la". */
    public static function verb(?self $gender, string $masculine): string
    {
        return match ($gender) {
            self::Masculine => $masculine,
            self::Feminine => mb_substr($masculine, 0, -1).'la',
            null => $masculine.'/la',
        };
    }
}
