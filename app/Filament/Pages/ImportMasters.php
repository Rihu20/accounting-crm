<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ImportMasters extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Masters';

    protected static ?string $navigationLabel = 'Import master Excel';

    protected static ?int $navigationSort = 9;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $title = 'Import masters';

    protected string $view = 'filament.pages.importmasters';

    public static function canAccess(): bool
    {
        return auth()->user()->allowed('accounting.masters.import');
    }

    public function getViewData(): array
    {
        return ['preview' => session('master_preview')];
    }
}
