<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class SheetsExport implements Export, WithMultipleSheets
{
    /** @param array<string, array> $sheets title => rows */
    public function __construct(private array $sheets) {}

    public function sheets(): array
    {
        return collect($this->sheets)->map(fn ($rows, $title) => new class($rows, $title) extends RowsExport implements WithTitle
        {
            public function __construct(array $rows, private string $sheetTitle)
            {
                parent::__construct($rows);
            }

            public function title(): string
            {
                return $this->sheetTitle;
            }
        })->values()->all();
    }
}
