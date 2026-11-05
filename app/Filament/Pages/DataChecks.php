<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class DataChecks extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'Data checks';

    protected static string|\UnitEnum|null $navigationGroup = 'Masters';

    protected string $view = 'filament.pages.data-checks';

    public static function canAccess(): bool
    {
        return auth()->user()?->allowed('accounting.masters.edit') ?? false;
    }
}
