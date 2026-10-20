<?php

namespace App\Enums;

enum CaseOutcome: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PartiallyApproved = 'partially_approved';
    case Withdrawn = 'withdrawn';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Odobreno', self::Rejected => 'Odbijeno', self::PartiallyApproved => 'Djelimično odobreno', self::Withdrawn => 'Povučeno', self::Other => 'Drugo',
        };
    }
}
