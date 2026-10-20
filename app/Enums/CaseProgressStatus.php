<?php

namespace App\Enums;

/**
 * Status postupka (u kodu: AdministrativeCase) — ne unosi se ručno, nego se
 * izračunava iz statusa njegovih stavki (vidi AdministrativeCase::progressStatus()).
 */
enum CaseProgressStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case WaitingResponse = 'waiting_response';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Nije pokrenuto',
            self::InProgress => 'U toku',
            self::WaitingResponse => 'Čeka odgovor',
            self::Completed => 'Završeno',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotStarted => 'zinc',
            self::InProgress => 'blue',
            self::WaitingResponse => 'orange',
            self::Completed => 'green',
        };
    }

    /** Ikona i boja pozadine ikone postupka u listi. */
    public function iconClass(): string
    {
        return match ($this) {
            self::NotStarted => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
            self::InProgress => 'bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-300',
            self::WaitingResponse => 'bg-orange-50 text-orange-600 dark:bg-orange-950/40 dark:text-orange-300',
            self::Completed => 'bg-green-50 text-green-600 dark:bg-green-950/40 dark:text-green-300',
        };
    }

    /**
     * Pravila iz plana: sve završene → Završeno; ima "Čeka odgovor" → Čeka odgovor;
     * ima u pripremi/predato/dopunu (ili dio završen) → U toku; ništa nije pokrenuto → Nije pokrenuto.
     *
     * @param  iterable<CaseItemStatus>  $statuses  statusi aktivnih stavki
     */
    public static function fromItemStatuses(iterable $statuses): self
    {
        $relevant = collect($statuses)->reject(fn (CaseItemStatus $status) => $status === CaseItemStatus::Cancelled);

        return match (true) {
            $relevant->isEmpty() => self::NotStarted,
            $relevant->every(fn (CaseItemStatus $status) => $status === CaseItemStatus::Completed) => self::Completed,
            $relevant->contains(CaseItemStatus::WaitingResponse) => self::WaitingResponse,
            $relevant->every(fn (CaseItemStatus $status) => $status === CaseItemStatus::NotStarted) => self::NotStarted,
            default => self::InProgress,
        };
    }
}
