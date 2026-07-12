<?php

namespace App\Filament\Resources;

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\PublishedContentResource\Pages\ListPublishedContent;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Publishing\ConnectionGate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The published body of work — every Content row live on WordPress — with a
 * Re-push action. The review queue only carries actionable (pre-publish) statuses,
 * so published pages had no home; this is the operator's record of what shipped
 * and the place to refresh it. Re-push re-renders and upserts the SAME post by
 * content_id (§2's idempotent-by-ULID PublishContent job — a page edited in
 * WordPress is skipped, never clobbered). Operator-only, read-mostly.
 */
class PublishedContentResource extends Resource
{
    protected static ?string $model = Content::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Published';

    // Superseded surface (unified-menu relay): Grow + the Live boards cover this — hidden, routes kept.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(SiteScope::class)
            ->where('status', ContentStatus::Published->value)
            ->orderByDesc('published_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->copyable()->fontFamily('mono')->size('xs')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('title')->searchable()->wrap()->limit(60),
                TextColumn::make('kind')->badge(),
                TextColumn::make('silo.name')->label('Silo')->placeholder('—'),
                TextColumn::make('wp_post_id')->label('WP post')->placeholder('—')->copyable(),
                TextColumn::make('published_at')->label('Published')->since()->sortable(),
                TextColumn::make('last_publish_error')->label('Note')->placeholder('—')->limit(40)->wrap()->color('warning'),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
            ])
            ->recordActions([
                self::repushAction(),
            ]);
    }

    /**
     * Re-push a live page — re-render its images and upsert the meta-blob to
     * WordPress again, keyed by content_id so it updates the same post (a refresh
     * re-publish). Gated on a verified, non-compromised connection for immediate
     * feedback; the actual work runs on the worker via the idempotent §2 job.
     */
    private static function repushAction(): Action
    {
        return Action::make('repush')
            ->label('Re-push')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('Re-renders this page and pushes it to WordPress again, updating the same post by content_id (idempotent). Runs on the worker; a page edited in WordPress is skipped, not overwritten.')
            ->action(function (Content $record): void {
                if (! app(ConnectionGate::class)->hasVerifiedWordpress($record->site_id)) {
                    Notification::make()->danger()
                        ->title('No verified WordPress connection')
                        ->body('Wire and verify one under Controls → Connections before re-pushing.')
                        ->send();

                    return;
                }

                PublishContent::dispatch($record->id, Auth::id());

                Notification::make()->success()
                    ->title('Re-push queued')
                    ->body("'{$record->title}' will be re-rendered and pushed to WordPress.")
                    ->send();
            });
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPublishedContent::route('/'),
        ];
    }
}
