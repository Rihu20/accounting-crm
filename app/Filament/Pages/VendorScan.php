<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class VendorScan extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Expenses';

    protected static ?string $navigationLabel = 'Scan vendor PDF';

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $title = 'Read vendor invoice';

    protected string $view = 'filament.pages.vendorscan';

    public static function canAccess(): bool
    {
        return auth()->user()->allowed('accounting.expenses.edit_all');
    }
}
