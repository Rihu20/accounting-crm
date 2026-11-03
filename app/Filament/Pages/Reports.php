<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Reports extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'Reports';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.reports';

    public static function canAccess(): bool
    {
        return auth()->user()->allowed('accounting.reports.view');
    }
}
