<?php

namespace App\Enums;

enum DocumentSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
    case ReviewRequired = 'review_required';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Čeka sinhronizaciju',
            self::Synced => 'Sinhronizovan',
            self::Failed => 'Greška sinhronizacije',
            self::ReviewRequired => 'Potrebna provjera',
        };
    }
}
