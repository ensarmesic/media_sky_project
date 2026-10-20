<?php

namespace App\Enums;

/** Status zadatka (stavke predmeta) — jedino mjesto s labelama i bojama statusa. */
enum CaseItemStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case InReview = 'in_review';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Novo',
            self::InProgress => 'U toku',
            self::Waiting => 'Na čekanju',
            self::InReview => 'Na pregledu',
            self::Completed => 'Završeno',
            self::Cancelled => 'Otkazano',
        };
    }

    /** Boja Flux badge-a / chipa. */
    public function color(): string
    {
        return match ($this) {
            self::New => 'zinc',
            self::InProgress => 'blue',
            self::Waiting => 'amber',
            self::InReview => 'violet',
            self::Completed => 'green',
            self::Cancelled => 'red',
        };
    }

    /** Tailwind klasa za tačku/obrub u boji statusa (kanban kolone, liste). */
    public function dotClass(): string
    {
        return match ($this) {
            self::New => 'bg-zinc-400',
            self::InProgress => 'bg-blue-500',
            self::Waiting => 'bg-amber-500',
            self::InReview => 'bg-violet-500',
            self::Completed => 'bg-emerald-500',
            self::Cancelled => 'bg-red-500',
        };
    }

    /** Završeni i otkazani zadaci nisu aktivni. */
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
