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

    /** Klase za status chip (lagana alternativa Flux badge-u u velikim tabelama). */
    public function chipClass(): string
    {
        return 'inline-flex items-center whitespace-nowrap rounded-md px-1.5 py-0.5 text-xs font-medium '.match ($this) {
            self::NotStarted => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
            self::InPreparation => 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',
            self::Submitted => 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
            self::WaitingResponse => 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300',
            self::SupplementRequired => 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300',
            self::Completed => 'bg-green-100 text-green-700 dark:bg-green-950/60 dark:text-green-300',
            self::Cancelled => 'bg-zinc-100 text-zinc-500 line-through dark:bg-zinc-800 dark:text-zinc-400',
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
