<?php

namespace App\Filament\Resources;

use App\Enums\ContentKind;
use App\Enums\UserRole;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Jobs\GeneratePage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
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
 * Operator surface for kind=page Content rows: "Generate page" queues the gated
 * Sonnet draft + fal render on the worker (same pattern as Generate post), and a
 * State column shows generating / drafted / failed. Operator-only. Drafting kit
 * design, client overrides, and Elementor mapping are out of this lean pass.
 */
class PageResource extends Resource
{
    protected static ?string $model = Content::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document';

    protected static ?string $navigationLabel = 'Pages';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(SiteScope::class)
            ->where('kind', ContentKind::Page->value)
            ->orderByDesc('created_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('title')->searchable()->wrap()->limit(60),
                TextColumn::make('page_type')->badge()->placeholder('—'),
                TextColumn::make('silo.name')->label('Silo')->placeholder('—'),
                TextColumn::make('generation_state')
                    ->label('State')
                    ->badge()
                    ->state(fn (Content $record): string => match ($record->generationState()) {
                        'drafted' => 'Drafted',
                        'generating' => 'Generating',
                        'failed' => 'Draft failed',
                        default => 'Awaiting draft',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Drafted' => 'success',
                        'Generating' => 'info',
                        'Draft failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
            ])
            ->recordActions([
                Action::make('generate')
                    ->label('Generate page')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->visible(fn (Content $record): bool => ! $record->isGenerating())
                    ->requiresConfirmation()
                    ->modalDescription('Queues the page draft (kit slots from brand voice + intake grounding, via Sonnet) and image render (fal) on the worker. The row shows "Generating" until the draft lands in Review.')
                    ->action(function (Content $record): void {
                        GeneratePage::queue($record, actorId: Auth::id());

                        Notification::make()->success()
                            ->title('Queued — generating on the worker')
                            ->body("'{$record->title}' is being drafted; it will appear in Review when ready.")
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
        ];
    }
}
