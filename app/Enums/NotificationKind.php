<?php

namespace App\Enums;

enum NotificationKind: string
{
    case DeadlineSoon = 'deadline_soon';
    case DeadlineOverdue = 'deadline_overdue';
    case WaitingTooLong = 'waiting_too_long';
    case SupplementRequired = 'supplement_required';
    case TaskAssigned = 'task_assigned';
    case StatusChanged = 'status_changed';
    case DocumentLinked = 'document_linked';
    case ResponseReceived = 'response_received';
    case ProjectCompleted = 'project_completed';

    public function label(): string
    {
        return match ($this) {
            self::DeadlineSoon => 'Rok ističe uskoro',
            self::DeadlineOverdue => 'Rok je prekoračen',
            self::WaitingTooLong => 'Predmet predugo čeka odgovor',
            self::SupplementRequired => 'Potrebna je dopuna dokumentacije',
            self::TaskAssigned => 'Dodijeljena je nova obaveza',
            self::StatusChanged => 'Promijenjen je važan status',
            self::DocumentLinked => 'Povezan je novi dokument',
            self::ResponseReceived => 'Zaprimljen je odgovor institucije',
            self::ProjectCompleted => 'Projekat je označen kao završen',
        };
    }
}
