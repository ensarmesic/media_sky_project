<?php

namespace App\Enums;

enum CaseItemType: string
{
    case Documentation = 'documentation';
    case Consent = 'consent';
    case Request = 'request';
    case WorkTask = 'work_task';
    case Check = 'check';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Documentation => 'Dokumentacija', self::Consent => 'Saglasnost',
            self::Request => 'Zahtjev', self::WorkTask => 'Radni zadatak',
            self::Check => 'Provjera', self::Other => 'Ostalo',
        };
    }
}
