<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Branding extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $title = 'Logo and signature';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.pages.branding';

    public static function canAccess(): bool
    {
        return auth()->user()->allowed('accounting.settings.manage');
    }
}
