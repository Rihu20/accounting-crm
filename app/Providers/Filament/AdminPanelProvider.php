<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\FirstRun;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** One panel for all four roles. Each role only sees the menus its permissions allow. */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')
            ->login()->profile()
            ->brandName('AnalystIP Accounts')
            ->brandLogo(fn () => view('filament.parts.brand'))
            ->brandLogoHeight('2.25rem')
            ->colors([
                'primary' => Color::Red,
                'danger' => Color::Red,
                'gray' => Color::Zinc,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'info' => Color::Sky,
            ])
            ->font('Fira Sans', url: fn () => asset('fonts/fira-sans/fira.css'), provider: \Filament\FontProviders\LocalFontProvider::class)
            ->darkMode(false)
            ->databaseTransactions()
            ->databaseNotifications()->databaseNotificationsPolling('60s')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->navigationGroups([
                NavigationGroup::make('Invoices')->icon(null),
                NavigationGroup::make('Expenses'),
                NavigationGroup::make('Masters')->collapsed(),
                NavigationGroup::make('Settings')->collapsed(),
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.parts.font'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.parts.role-chip'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->homeUrl('/admin')
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, FirstRun::class, AuthenticateSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
            ->authMiddleware([Authenticate::class, ActiveUser::class], isPersistent: true);
    }
}
