<?php

namespace App\Exports;

use App\Enums\CaseItemStatus;
use App\Models\AdministrativeCaseItem;
use OpenSpout\Common\Entity\Cell;

/** Kolone lista "Stavke" — iste u izvozu projekta i izvozu po instituciji. */
final class ItemRows
{
    /** @return array<string, float> */
    public static function columns(): array
    {
        return [
            'Projekat' => 24, 'Postupak' => 28, '#' => 5, 'Stavka' => 40, 'Status' => 16, 'Institucija / primalac' => 26,
            'Rok' => 12, 'Predaja' => 12, 'Odgovor' => 12, 'Dana čeka odgovor' => 10, 'Odgovorna osoba' => 22,
            'Dokumenti' => 10, 'Kasni' => 8,
        ];
    }

    /**
     * Stavka mora imati učitane administrativeCase.project, institution, responsible i documents_count.
     *
     * @return list<Cell|\DateTimeInterface|string|int|float|bool|null>
     */
    public static function row(AdministrativeCaseItem $item, int $number): array
    {
        $overdue = $item->isOverdue();

        return [
            $item->administrativeCase->project->name,
            $item->administrativeCase->name,
            $number,
            $item->name,
            XlsxWorkbook::status($item->status->label(), $item->status->color()),
            $item->recipientName(),
            $overdue ? XlsxWorkbook::danger($item->due_date) : $item->due_date,
            $item->submitted_at,
            $item->response_at,
            $item->status === CaseItemStatus::WaitingResponse ? $item->daysWaiting() : null,
            $item->responsible?->name,
            (int) ($item->getAttribute('documents_count') ?? 0),
            $overdue ? XlsxWorkbook::danger('Da') : 'Ne',
        ];
    }
}
