<?php

namespace App\Exports;

use App\Models\AdministrativeCaseItem;
use App\Models\Institution;
use App\Models\User;
use App\Services\InstitutionOverview;
use Illuminate\Support\Str;

/** Izvoz otvorenih stavki jedne institucije (samo iz projekata koje korisnik vidi), iste kolone kao list "Stavke". */
final class InstitutionExport
{
    public function __construct(private Institution $institution, private User $user) {}

    public function filename(): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', Str::ascii($this->institution->name)), '-');

        return ($name !== '' ? $name : 'institucija').'_'.now()->format('d-m-Y').'.xlsx';
    }

    public function store(string $path): void
    {
        $groups = app(InstitutionOverview::class)->groups($this->institution, $this->user);
        $columns = ['Grupa' => 16, ...ItemRows::columns()];

        $rows = (function () use ($groups) {
            foreach (InstitutionOverview::GROUPS as $key => $label) {
                foreach ($groups[$key]->values() as $i => $item) {
                    /** @var AdministrativeCaseItem $item */
                    yield [$label, ...ItemRows::row($item, $i + 1)];
                }
            }
        })();

        $workbook = new XlsxWorkbook($path);
        $workbook->sheet('Stavke', $columns, $rows);
        $workbook->close();
    }
}
