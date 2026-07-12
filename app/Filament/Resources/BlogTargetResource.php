<?php

namespace App\Filament\Resources;

use App\ContentEngine\BlogQueue\BlogTargetQueue;
use App\Enums\BlogTargetStatus;
use App\Filament\Resources\BlogTargetResource\Pages\ListBlogTargets;
use App\Models\BlogTarget;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The BLOG TARGET QUEUE surface (longtail relay v1): per-silo, read-only — the ordered list of
 * unconsumed informational keywords the directed news-post lane draws from, plus the dismiss
 * opt-out. Deliberately NOT a workbench: consumption happens through drafting, never here.
 */
class BlogTargetResource extends Resource
{
    protected static ?string $model = BlogTarget::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Blog targets';

    protected static string|\UnitEnum|null $navigationGroup = 'Work';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('silo.name')->label('Silo')->sortable(),
                TextColumn::make('keyword.query')->label('Keyword')->searchable(),
                TextColumn::make('keyword.intent')->label('Intent')->badge()->placeholder('—'),
                TextColumn::make('keyword.volume')->label('Searches')->numeric()->sortable()->placeholder('—'),
                TextColumn::make('status')->badge()
                    ->color(fn (BlogTargetStatus $state): string => match ($state) {
                        BlogTargetStatus::Queued => 'info',
                        BlogTargetStatus::Drafted => 'warning',
                        BlogTargetStatus::Published => 'success',
                        BlogTargetStatus::Dismissed => 'gray',
                    }),
                TextColumn::make('queued_at')->since()->sortable(),
            ])
            ->defaultSort('queued_at')
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('silo_id')->label('Silo')->relationship('silo', 'name'),
                SelectFilter::make('status')->options(
                    collect(BlogTargetStatus::cases())->mapWithKeys(fn (BlogTargetStatus $s) => [$s->value => $s->label()])->all()
                ),
            ])
            ->recordActions([
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (BlogTarget $record): bool => $record->status === BlogTargetStatus::Queued)
                    ->action(function (BlogTarget $record): void {
                        app(BlogTargetQueue::class)->dismiss($record);
                        Notification::make()->success()->title('Target dismissed — it leaves the directed lane.')->send();
                    }),
            ]);
    }

    public static function canCreate(): bool
    {
        return false; // queue rows are routed by the prune + materialize, never hand-created
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListBlogTargets::route('/'),
        ];
    }
}
