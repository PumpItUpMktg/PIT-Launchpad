<?php

namespace App\Filament\Resources;

use App\Enums\EditReason;
use App\Enums\UserRole;
use App\Filament\Resources\ContentEditResource\Pages\ListContentEdits;
use App\Models\ContentEdit;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The §7 admin tick — captured operator corrections, read-only, with a navigation count badge.
 * This is the "is the backend telling on itself?" log; the read-across analytics view (clustering
 * by user / silo / reason) lands later once there's edit volume to read. Operator-only.
 */
class ContentEditResource extends Resource
{
    protected static ?string $model = ContentEdit::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Edit signal';

    protected static string|\UnitEnum|null $navigationGroup = 'Advanced';

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Operator;
    }

    /** The admin tick: how many corrections have been captured. */
    public static function getNavigationBadge(): ?string
    {
        $count = ContentEdit::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('content.title')->label('Page')->limit(40)->placeholder('—'),
                TextColumn::make('field')->badge()->color('gray'),
                TextColumn::make('reason')
                    ->badge()
                    ->formatStateUsing(fn (EditReason $state): string => $state->label())
                    ->color(fn (EditReason $state): string => match ($state) {
                        EditReason::OffBase => 'danger',
                        EditReason::OffBrand => 'warning',
                        EditReason::Preference => 'gray',
                    }),
                TextColumn::make('original')->limit(50)->wrap()->placeholder('—'),
                TextColumn::make('edited')->limit(50)->wrap()->placeholder('—'),
                TextColumn::make('created_at')->label('When')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('reason')->options(collect(EditReason::cases())
                    ->mapWithKeys(fn (EditReason $r) => [$r->value => $r->label()])->all()),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListContentEdits::route('/'),
        ];
    }
}
