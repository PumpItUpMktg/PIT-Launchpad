<?php

namespace App\Filament\Resources;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
use App\Jobs\GeneratePost;
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
 * §6a routed news candidates awaiting drafting — the entry point of the ongoing
 * content flow. "Generate post" is the gated, expensive step (Sonnet draft + fal
 * image); it never auto-fires. Drafting transitions the candidate in place into
 * the review queue.
 */
class CandidateResource extends Resource
{
    protected static ?string $model = Content::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationLabel = 'Candidates';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(SiteScope::class)
            // POST lane only. §4 pillar stubs are kind=page + status=candidate; without
            // this filter they leaked in here and "Generate post" flipped them to posts
            // (DraftRequest::forCandidate hard-codes kind=Post), so a service pillar
            // published through the blog template. Pages generate via PageResource.
            ->where('kind', ContentKind::Post->value)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::Scored->value])
            ->orderByDesc('relevance_score');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('title')->searchable()->wrap()->limit(70),
                TextColumn::make('source_name')->label('Source')->placeholder('—'),
                TextColumn::make('relevance_score')->label('Score')->numeric(2)->sortable(),
                TextColumn::make('generation_state')
                    ->label('State')
                    ->badge()
                    ->state(fn (Content $record): string => match ($record->generationState()) {
                        'generating' => 'Generating',
                        'failed' => 'Draft failed',
                        default => 'New',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Generating' => 'info',
                        'Draft failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Found')->since()->sortable(),
            ])
            ->defaultSort('relevance_score', 'desc')
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
            ])
            ->recordActions([
                Action::make('generate')
                    ->label('Generate post')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (Content $record): bool => ! $record->hasDraft() && ! $record->isGenerating())
                    ->requiresConfirmation()
                    ->modalDescription('Queues the draft (brand voice + grounding via Sonnet) and image render (fal) on the worker — the expensive step runs in the background, not in this request. The row shows "Generating" until it lands in Review.')
                    ->action(function (Content $record): void {
                        GeneratePost::enqueue($record, actorId: Auth::id());

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
            'index' => ListCandidates::route('/'),
        ];
    }
}
