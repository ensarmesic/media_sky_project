<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    /** Zadržano zbog starih zapisa; u interfejsu se prikazuje kao "Na čekanju". */
    case Suspended = 'suspended';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Nije pokrenuto',
            self::InProgress => 'U toku',
            self::OnHold, self::Suspended => 'Na čekanju',
            self::Completed => 'Završeno',
            self::Archived => 'Arhivirano',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'zinc',
            self::InProgress => 'blue',
            self::OnHold, self::Suspended => 'orange',
            self::Completed => 'green',
            self::Archived => 'zinc',
        };
    }

    /**
     * Statusi koji se nude u izboru (bez zastarjelog "suspended").
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [self::Planned, self::InProgress, self::OnHold, self::Completed, self::Archived];
    }

    /**
     * Vrijednosti koje filter u sidebaru tretira kao isti status.
     *
     * @return list<string>
     */
    public function filterValues(): array
    {
        return $this === self::OnHold ? [self::OnHold->value, self::Suspended->value] : [$this->value];
    }

    /** Statusi koji znače da je projekat izbačen iz aktivnog rada. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Archived], true);
    }
}
