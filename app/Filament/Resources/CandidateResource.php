<?php

namespace App\Filament\Resources;

use App\ContentEngine\Generation\PostGenerator;
use App\Enums\ContentStatus;
use App\Filament\Resources\CandidateResource\Pages\ListCandidates;
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
                    ->requiresConfirmation()
                    ->modalDescription('Drafts the post with brand voice + grounding (Sonnet) and renders its image (fal). This is the expensive step and runs only when you confirm.')
                    ->action(function (Content $record): void {
                        $result = app(PostGenerator::class)->generate($record);

                        Notification::make()->success()
                            ->title('Post generated → review queue')
                            ->body("'{$result->content->title}' is drafted and waiting in Review.")
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
