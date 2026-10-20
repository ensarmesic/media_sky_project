<?php

namespace App\Enums;

/** Prioritet zadatka. */
enum CaseItemPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Nizak',
            self::Medium => 'Srednji',
            self::High => 'Visok',
            self::Urgent => 'Hitno',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'zinc',
            self::Medium => 'blue',
            self::High => 'amber',
            self::Urgent => 'red',
        };
    }

    /** Redoslijed za sortiranje (hitno prvo). */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
        };
    }
}
