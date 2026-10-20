<?php

namespace App\Enums;

/** Status stavke (u kodu: AdministrativeCaseItem) — jedino mjesto s labelama i bojama. */
enum CaseItemStatus: string
{
    case NotStarted = 'not_started';
    case InPreparation = 'in_preparation';
    case Submitted = 'submitted';
    case WaitingResponse = 'waiting_response';
    case SupplementRequired = 'supplement_required';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Nije pokrenuto',
            self::InPreparation => 'U pripremi',
            self::Submitted => 'Predato',
            self::WaitingResponse => 'Čeka odgovor',
            self::SupplementRequired => 'Potrebna dopuna',
            self::Completed => 'Završeno',
            self::Cancelled => 'Otkazano',
        };
    }

    /** Boja Flux badge-a (prema referentnom dizajnu). */
    public function color(): string
    {
        return match ($this) {
            self::NotStarted => 'zinc',
            self::InPreparation => 'sky',
            self::Submitted => 'blue',
            self::WaitingResponse => 'orange',
            self::SupplementRequired => 'red',
            self::Completed => 'green',
            self::Cancelled => 'zinc',
        };
    }

    /** Tailwind klasa za tačku u boji statusa. */
    public function dotClass(): string
    {
        return match ($this) {
            self::NotStarted, self::Cancelled => 'bg-zinc-400',
            self::InPreparation => 'bg-sky-400',
            self::Submitted => 'bg-blue-600',
            self::WaitingResponse => 'bg-orange-500',
            self::SupplementRequired => 'bg-red-500',
            self::Completed => 'bg-green-500',
        };
    }

    /** Završene i otkazane stavke nisu aktivne. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => ! $status->isClosed()));
    }

    /** @return list<string> */
    public static function closedValues(): array
    {
        return [self::Completed->value, self::Cancelled->value];
    }
}
