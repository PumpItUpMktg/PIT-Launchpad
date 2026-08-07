<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ClientPanelProvider;
use App\Providers\Filament\ConsolePanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ClientPanelProvider::class,
    ConsolePanelProvider::class,
];
