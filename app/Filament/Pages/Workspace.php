<?php

namespace App\Filament\Pages;

use App\Services\DashboardService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;

class Workspace extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $title = 'Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.pages.workspace';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->allowed('platform.staff.access');
    }

    public function getHeading(): string
    {
        return '';
    }

    protected function getViewData(): array
    {
        return ['d' => DashboardService::for(auth()->user()), 'user' => auth()->user()];
    }
}
