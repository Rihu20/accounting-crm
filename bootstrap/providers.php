<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\PlatformServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    PlatformServiceProvider::class,
];
