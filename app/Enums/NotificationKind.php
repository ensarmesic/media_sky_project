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
    case Mentioned = 'mentioned';
    case TaskCommented = 'task_commented';
    case DocumentLinked = 'document_linked';
    case ResponseReceived = 'response_received';
    case ProjectCompleted = 'project_completed';

    public function label(): string
    {
        return match ($this) {
            self::DeadlineSoon => 'Rok ističe uskoro',
            self::DeadlineOverdue => 'Rok je prekoračen',
            self::WaitingTooLong => 'Zadatak predugo čeka odgovor',
            self::SupplementRequired => 'Potrebna je dopuna dokumentacije',
            self::TaskAssigned => 'Dodijeljen mi je zadatak',
            self::StatusChanged => 'Promijenjen je status mog zadatka',
            self::Mentioned => 'Neko me spomenuo u komentaru',
            self::TaskCommented => 'Novi komentar na mom zadatku',
            self::DocumentLinked => 'Povezan je novi dokument',
            self::ResponseReceived => 'Zaprimljen je odgovor institucije',
            self::ProjectCompleted => 'Projekat je označen kao završen',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DeadlineSoon, self::DeadlineOverdue, self::WaitingTooLong => 'clock',
            self::TaskAssigned => 'user-plus',
            self::Mentioned => 'at-symbol',
            self::TaskCommented => 'chat-bubble-left',
            self::StatusChanged => 'arrow-path',
            self::DocumentLinked => 'paper-clip',
            default => 'bell',
        };
    }
}
