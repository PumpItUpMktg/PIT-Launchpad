<?php

namespace App\Filament\Resources;

use App\Enums\VoiceStatus;
use App\Filament\Resources\VoiceProfileResource\Pages\ListVoiceProfiles;
use App\Models\Site;
use App\Models\VoiceProfile;
use App\Operator\Controls\VoiceControl;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * VoiceProfile control: view the versioned profiles per tenant, the active
 * version, and how many recent content rows pin each version. Activating a
 * version archives the prior active (one active per site).
 */
class VoiceProfileResource extends Resource
{
    protected static ?string $model = VoiceProfile::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Voice';

    protected static string|\UnitEnum|null $navigationGroup = 'Controls';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('version')->badge()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('framing_model')->placeholder('—'),
                TextColumn::make('pinned')
                    ->label('Pinned on content')
                    ->state(function (VoiceProfile $record): int {
                        $site = $record->site;

                        return $site instanceof Site
                            ? (app(VoiceControl::class)->pinnedVersions($site)[$record->version] ?? 0)
                            : 0;
                    }),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('status')->options(self::statusOptions()),
            ])
            ->recordActions([
                Action::make('activate')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (VoiceProfile $record): bool => $record->status !== VoiceStatus::Active)
                    ->action(function (VoiceProfile $record): void {
                        app(VoiceControl::class)->activate($record);
                        Notification::make()->success()->title('Voice version activated')->send();
                    }),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (VoiceStatus::cases() as $status) {
            $options[$status->value] = ucfirst($status->value);
        }

        return $options;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVoiceProfiles::route('/'),
        ];
    }
}
