<?php

namespace App\Enums;

enum CaseItemStatus: string
{
    case NotStarted = 'not_started';
    case InPreparation = 'in_preparation';
    case ReadyToSubmit = 'ready_to_submit';
    case Submitted = 'submitted';
    case WaitingResponse = 'waiting_response';
    case SupplementRequired = 'supplement_required';
    case Completed = 'completed';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Nije započeto', self::InPreparation => 'U pripremi',
            self::ReadyToSubmit => 'Spremno za predaju', self::Submitted => 'Predato',
            self::WaitingResponse => 'Čeka odgovor', self::SupplementRequired => 'Potrebna dopuna',
            self::Completed => 'Završeno', self::NotApplicable => 'Nije primjenjivo',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotStarted => 'zinc', self::InPreparation => 'blue', self::ReadyToSubmit => 'teal',
            self::Submitted => 'violet', self::WaitingResponse => 'amber', self::SupplementRequired => 'red',
            self::Completed => 'green', self::NotApplicable => 'zinc',
        };
    }

    /** Statusi koji se računaju kao aktivni rad na stavci. */
    public function isWorkInProgress(): bool
    {
        return in_array($this, [self::NotStarted, self::InPreparation, self::ReadyToSubmit], true);
    }
}
