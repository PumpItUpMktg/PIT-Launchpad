<?php

namespace App\Filament\Client\Resources;

use App\Client\ClientContext;
use App\ContentEngine\Feeds\FeedHealth;
use App\Enums\FeedOrigin;
use App\Enums\FeedStatus;
use App\Filament\Client\Resources\NewsSourceResource\Pages\CreateNewsSource;
use App\Filament\Client\Resources\NewsSourceResource\Pages\ListNewsSources;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Source;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §6a Phase 2 — the client-facing News Sources panel. A client adds, previews,
 * pauses and removes their own direct RSS/Atom feeds; generated (Google News)
 * feeds are operator-managed and never appear here. Scoped hard to the client's
 * current site and to origin=client so one tenant can never see another's feeds.
 */
class NewsSourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rss';

    protected static ?string $navigationLabel = 'News Sources';

    protected static ?string $modelLabel = 'news source';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(SiteScope::class)
            ->where('site_id', app(ClientContext::class)->site()?->id)
            ->where('origin', FeedOrigin::Client->value);
    }

    public static function canCreate(): bool
    {
        return app(ClientContext::class)->site() !== null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Source')->searchable()->wrap(),
                TextColumn::make('url')->label('Feed URL')->limit(48)->color('gray')->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Source $record): string => ucfirst(app(FeedHealth::class)->status($record)->value))
                    ->color(fn (Source $record): string => match (app(FeedHealth::class)->status($record)) {
                        FeedStatus::Active => 'success',
                        FeedStatus::Paused => 'gray',
                        FeedStatus::Unhealthy => 'danger',
                    }),
                TextColumn::make('last_item_at')->label('Last article')->since()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (Source $record): string => $record->enabled ? 'Pause' : 'Resume')
                    ->icon('heroicon-o-power')
                    ->action(fn (Source $record) => $record->forceFill(['enabled' => ! $record->enabled])->save()),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No news sources yet')
            ->emptyStateDescription('Add an RSS or Atom feed URL to pull articles from outlets you trust.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('url')
                ->label('RSS / Atom feed URL')
                ->required()
                ->url()
                ->helperText('Paste the feed URL. We check it is reachable and shows recent articles before adding it.'),
            TextInput::make('label')
                ->label('Name (optional)')
                ->helperText('A friendly name for this source. Defaults to the outlet name.'),
            Select::make('silo_id')
                ->label('Route to a topic (optional)')
                ->options(fn (): array => Silo::withoutGlobalScope(SiteScope::class)
                    ->where('site_id', app(ClientContext::class)->site()?->id)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->helperText('Leave blank to let relevance scoring route articles automatically.'),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListNewsSources::route('/'),
            'create' => CreateNewsSource::route('/create'),
        ];
    }
}
