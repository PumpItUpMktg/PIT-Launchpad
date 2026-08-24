<?php

namespace App\Filament\Resources;

use App\ContentEngine\Review\AlertFlags;
use App\ContentEngine\Review\ReviewQueue;
use App\Enums\ContentKind;
use App\Enums\DraftTrigger;
use App\Enums\ReviewFlag;
use App\Enums\UserRole;
use App\Filament\Resources\Concerns\ContentReviewActions;
use App\Filament\Resources\ContentReviewResource\Pages\EditContentReview;
use App\Filament\Resources\ContentReviewResource\Pages\ListContentReviews;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The §6c operator review queue: lists `needs_review` (and bounced-back) drafts
 * flagged-first, filterable per tenant/silo/kind/trigger/alert, with the
 * approve→publish wiring that closes the pipeline. A thin Filament surface over
 * the testable ReviewActions / AlertFlags / ReviewQueue services. Operator-only.
 *
 * GEO-lane drafts are reviewed on their own AI-section page ({@see AiContentResource});
 * this queue scopes them out so the blog review stays blog-only.
 */
class ContentReviewResource extends Resource
{
    use ContentReviewActions;

    protected static ?string $model = Content::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Review queue';

    protected static string|\UnitEnum|null $navigationGroup = 'Local Blog';

    /** Superseded by Operate → Blog: hidden once the new Operate menu is on (flag off ⇒ unchanged). */
    public static function shouldRegisterNavigation(): bool
    {
        return ! config('launchpad.new_operate_enabled');
    }

    /** Menu-map family tag: duplicated by the Operate Blog surface; retired at cutover. */
    public static function menuTag(): string
    {
        return 'operate';
    }

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'draft';

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(SiteScope::class)
            ->whereIn('status', ReviewQueue::statusValues())
            // GEO-lane drafts are reviewed in the AI section (AI → AI Content), not the blog review queue.
            ->excludingGeoLane()
            ->orderByRaw(ReviewQueue::priorityOrder())
            ->orderBy('created_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->limit(40)->wrap(),
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('silo.name')->label('Silo')->placeholder('—'),
                TextColumn::make('kind')->badge(),
                TextColumn::make('draft_state')
                    ->label('Draft')
                    ->badge()
                    ->state(fn (Content $record): string => self::draftState($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Drafted' => 'success',
                        'Generating' => 'info',
                        'Draft failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('draft_trigger')->label('Lane')->badge()->placeholder('—'),
                TextColumn::make('flags')
                    ->label('Flags')
                    ->badge()
                    ->state(fn (Content $record): array => array_map(
                        fn (ReviewFlag $flag) => $flag->label(),
                        AlertFlags::for($record),
                    )),
                TextColumn::make('created_at')->label('Age')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name'),
                SelectFilter::make('silo_id')->label('Silo')->relationship('silo', 'name'),
                SelectFilter::make('kind')->options(self::enumOptions(ContentKind::cases())),
                SelectFilter::make('draft_trigger')->label('Lane')->options(self::enumOptions(DraftTrigger::cases())),
                SelectFilter::make('flag')
                    ->label('Alert')
                    ->options(self::flagOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        $flag = is_string($value) ? ReviewFlag::tryFrom($value) : null;

                        return $flag !== null ? AlertFlags::filter($query, $flag) : $query;
                    }),
            ])
            ->recordActions([
                self::generateAction(),
                self::approveAction(),
                self::publishNowAction(),
                self::rejectAction(),
                self::lockAction(),
            ])
            ->bulkActions([
                self::bulkApproveAction(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListContentReviews::route('/'),
            'edit' => EditContentReview::route('/{record}/edit'),
        ];
    }
}
