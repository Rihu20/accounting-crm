<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class RowsExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder
{
    public function __construct(private array $rows) {}

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && (! is_numeric($value) || preg_match('/^0\d+$/', $value))) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function array(): array
    {
        return $this->rows;
    }
}
