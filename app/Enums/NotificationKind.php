<?php

namespace App\Enums;

enum NotificationKind: string
{
    case DeadlineSoon = 'deadline_soon';
    case DeadlineOverdue = 'deadline_overdue';
    case WaitingTooLong = 'waiting_too_long';
    case SupplementRequired = 'supplement_required';
    case TaskAssigned = 'task_assigned';
    case ProjectMember = 'project_member';
    case StatusChanged = 'status_changed';
    case Mentioned = 'mentioned';
    case TaskCommented = 'task_commented';
    case DocumentLinked = 'document_linked';
    case ResponseReceived = 'response_received';
    case ProjectCompleted = 'project_completed';
    case BackupProblem = 'backup_problem';

    public function label(): string
    {
        return match ($this) {
            self::DeadlineSoon => 'Rok ističe uskoro',
            self::DeadlineOverdue => 'Rok je prekoračen',
            self::WaitingTooLong => 'Stavka predugo čeka odgovor',
            self::SupplementRequired => 'Potrebna je dopuna dokumentacije',
            self::TaskAssigned => 'Dodijeljena mi je stavka',
            self::ProjectMember => 'Dodavanje u projekat',
            self::StatusChanged => 'Promijenjen je status moje stavke',
            self::Mentioned => 'Neko me spomenuo u komentaru',
            self::TaskCommented => 'Komentar ili dokument na mojoj stavci',
            self::DocumentLinked => 'Povezan je novi dokument',
            self::ResponseReceived => 'Zaprimljen je odgovor institucije',
            self::ProjectCompleted => 'Projekat je označen kao završen',
            self::BackupProblem => 'Problem s backupom (samo admin)',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DeadlineSoon, self::DeadlineOverdue, self::WaitingTooLong => 'clock',
            self::TaskAssigned, self::ProjectMember => 'user-plus',
            self::Mentioned => 'at-symbol',
            self::TaskCommented => 'chat-bubble-left',
            self::StatusChanged => 'arrow-path',
            self::DocumentLinked => 'paper-clip',
            self::BackupProblem => 'circle-stack',
            default => 'bell',
        };
    }
}
