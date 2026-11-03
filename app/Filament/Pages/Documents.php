<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Documents extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Invoices';

    protected static ?string $navigationLabel = 'Matter documents';

    protected static ?int $navigationSort = 6;

    public static function shouldRegisterNavigation(): bool
    {
        return filament()->getCurrentPanel()?->getId() !== 'portal' && parent::shouldRegisterNavigation();
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $title = 'Documents';

    protected string $view = 'filament.pages.documents';

    public static function canAccess(): bool
    {
        return auth()->user()->allowed('accounting.documents.manage');
    }
}
