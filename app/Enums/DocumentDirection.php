<?php

namespace App\Enums;

enum DocumentDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Incoming => 'Ulazni',
            self::Outgoing => 'Izlazni',
            self::Internal => 'Interni',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Incoming => 'blue',
            self::Outgoing => 'violet',
            self::Internal => 'zinc',
        };
    }
}
