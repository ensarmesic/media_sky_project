<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Suspended = 'suspended';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planiran',
            self::InProgress => 'U toku',
            self::OnHold => 'Na čekanju',
            self::Suspended => 'Privremeno obustavljen',
            self::Completed => 'Završen',
            self::Archived => 'Arhiviran',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'zinc',
            self::InProgress => 'blue',
            self::OnHold => 'amber',
            self::Suspended => 'red',
            self::Completed => 'green',
            self::Archived => 'zinc',
        };
    }

    /** Statusi koji znače da je projekat izbačen iz aktivnog rada. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Archived], true);
    }
}
