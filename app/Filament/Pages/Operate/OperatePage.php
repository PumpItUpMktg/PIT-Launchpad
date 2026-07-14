<?php

namespace App\Filament\Pages\Operate;

use BackedEnum;
use Filament\Pages\Page;

/**
 * Base for the NEW Operate group (operate relay — parallel build, sibling of the Setup group).
 * Gated by `launchpad.new_operate_enabled`: off ⇒ the admin is identical to before; on ⇒ the
 * Operate group appears (Dashboard, Blog, plus Grow/Live re-registered under it). Cross-tenant
 * first: the operator's day starts at the Dashboard and works attention items to zero.
 */
abstract class OperatePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-command-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Operate';

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('launchpad.new_operate_enabled');
    }
}
