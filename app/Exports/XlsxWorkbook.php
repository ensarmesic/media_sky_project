<?php

namespace App\Exports;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Tanki sloj nad OpenSpout-om: list sa podebljanim i zamrznutim zaglavljem, autofilterom i širinama kolona;
 * datumi kao pravi Excel datumi (dd.mm.yyyy.), statusi u bojama kao u aplikaciji.
 */
final class XlsxWorkbook
{
    public const DATE_FORMAT = 'dd.mm.yyyy.';

    /** Boje statusa (pozadina, tekst) — iste nijanse kao chipovi u aplikaciji. */
    private const COLORS = [
        'zinc' => ['F4F4F5', '52525B'],
        'sky' => ['E0F2FE', '0369A1'],
        'blue' => ['DBEAFE', '1D4ED8'],
        'orange' => ['FFEDD5', 'C2410C'],
        'red' => ['FEE2E2', 'B91C1C'],
        'green' => ['DCFCE7', '15803D'],
        'violet' => ['EDE9FE', '6D28D9'],
    ];

    private Writer $writer;

    private bool $firstSheet = true;

    public function __construct(string $path)
    {
        $this->writer = new Writer;
        $this->writer->openToFile($path);
    }

    /**
     * @param  array<string, float>  $columns  naslov kolone => širina
     * @param  iterable<int, list<Cell|DateTimeInterface|string|int|float|bool|null>>  $rows
     */
    public function sheet(string $name, array $columns, iterable $rows): void
    {
        $sheet = $this->firstSheet ? $this->writer->getCurrentSheet() : $this->writer->addNewSheetAndMakeItCurrent();
        $this->firstSheet = false;
        $sheet->setName(mb_substr($name, 0, 31));
        // Širine i zamrznuti red upisuju se prije podataka; autofilter na kraju (zna broj redova).
        $index = 1;
        foreach ($columns as $width) {
            $sheet->setColumnWidth($width, $index++);
        }
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        $header = (new Style)->setFontBold()->setBackgroundColor('E4E4E7');
        $this->writer->addRow(Row::fromValuesWithStyles(array_keys($columns), $header));

        $count = 0;
        foreach ($rows as $values) {
            $this->writer->addRow(new Row(array_map(fn ($value) => $value instanceof Cell ? $value : self::cell($value), $values)));
            $count++;
        }

        $sheet->setAutoFilter(new AutoFilter(0, 1, max(0, count($columns) - 1), $count + 1));
    }

    public function close(): void
    {
        $this->writer->close();
    }

    public static function cell(DateTimeInterface|string|int|float|bool|null $value, ?Style $style = null): Cell
    {
        if ($value instanceof DateTimeInterface) {
            return Cell::fromValue($value, ($style ?? new Style)->setFormat(self::DATE_FORMAT));
        }

        return Cell::fromValue($value, $style);
    }

    /** Ćelija statusa u boji (flux naziv boje iz enuma: green, orange, blue, sky, red, zinc...). */
    public static function status(string $label, string $color): Cell
    {
        [$background, $font] = self::COLORS[$color] ?? self::COLORS['zinc'];

        return Cell::fromValue($label, (new Style)->setBackgroundColor($background)->setFontColor($font)->setFontBold());
    }

    /** Crveni tekst (stavke koje kasne, istekli rokovi). */
    public static function danger(DateTimeInterface|string|null $value): Cell
    {
        return self::cell($value, (new Style)->setFontColor('DC2626')->setFontBold());
    }
}
