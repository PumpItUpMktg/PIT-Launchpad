<?php

namespace App\Filament\Resources;

use App\ContentEngine\Review\AlertFlags;
use App\Enums\ContentStatus;
use App\Enums\ReviewFlag;
use App\Enums\UserRole;
use App\Filament\Resources\AiContentResource\Pages\EditAiContent;
use App\Filament\Resources\AiContentResource\Pages\ListAiContent;
use App\Filament\Resources\Concerns\ContentReviewActions;
use App\Filament\Widgets\GeoContentSummaryWidget;
use App\Geo\GeoGapBridge;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Support\WorkingTenant;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The AI-search content review — GEO-lane posts (materialized from absent AI-search gaps by
 * {@see GeoGapBridge}) get their own home in the AI section instead of diluting the blog
 * Candidates + Review queues. One page carries them through the whole pre-publish flow: bridged
 * candidate → Generate post → review/edit → Approve → Publish. Published GEO content then lives in
 * the normal Published surface; a per-silo published tally rides the list header
 * ({@see GeoContentSummaryWidget}) so the operator sees the body of work land.
 *
 * Actions + edit form are single-sourced with the blog review queue via {@see ContentReviewActions}.
 * Operator-only.
 */
class AiContentResource extends Resource
{
    use ContentReviewActions;

    protected static ?string $model = Content::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'AI Content';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 8;

    protected static ?string $modelLabel = 'AI post';

    /**
     * Pre-publish GEO-lane statuses: the bridged candidate through every review state. Published +
     * rejected drop off (published shows in the header tally + the Published surface).
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            ContentStatus::Candidate->value,
            ContentStatus::Scored->value,
            ContentStatus::NeedsReview->value,
            ContentStatus::InReview->value,
            ContentStatus::RenderFailed->value,
            ContentStatus::PublishFailed->value,
        ];
    }

    /** Menu-map family tag: placement/exposure still pending the cutover decision (with the GEO screens). */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(SiteScope::class)
            ->where('draft_lane', Content::GEO_LANE)
            ->whereIn('status', self::statuses())
            // Blocked (render/publish failed) first, then review, then fresh candidates; newest within a band.
            ->orderByRaw("case status
                when 'render_failed' then 0
                when 'publish_failed' then 1
                when 'needs_review' then 2
                when 'in_review' then 3
                else 4 end")
            ->orderByDesc('created_at');
    }

    /**
     * The tenant the page opens scoped to: the operator's session working site, else the first tenant
     * that already has GEO-lane content. Null (all tenants) only when nothing is selected and none exist.
     */
    private static function defaultTenantId(): ?string
    {
        $fallback = Content::withoutGlobalScope(SiteScope::class)
            ->where('draft_lane', Content::GEO_LANE)
            ->value('site_id');

        return WorkingTenant::id() ?? (is_string($fallback) ? $fallback : null);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->limit(48)->wrap(),
                TextColumn::make('site.brand_name')->label('Tenant')->sortable(),
                TextColumn::make('silo.name')->label('Silo')->placeholder('—'),
                TextColumn::make('gap')
                    ->label('AI gap')
                    ->state(fn (Content $record): string => self::gapLabel($record))
                    ->placeholder('—'),
                TextColumn::make('status')->badge(),
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
                SelectFilter::make('site_id')->label('Tenant')->relationship('site', 'brand_name')
                    ->default(self::defaultTenantId()),
                SelectFilter::make('silo_id')->label('Silo')->relationship('silo', 'name'),
                SelectFilter::make('status')->options(self::enumOptions(ContentStatus::cases())),
            ], layout: FiltersLayout::AboveContent)
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
            'index' => ListAiContent::route('/'),
            'edit' => EditAiContent::route('/{record}/edit'),
        ];
    }

    /**
     * The AI-search gap this post answers — market · intent, from the bridge's provenance meta. Blank
     * for a GEO-lane row that predates the meta (nothing to show rather than a broken read).
     */
    private static function gapLabel(Content $record): string
    {
        $gap = is_array($record->meta['geo_gap'] ?? null) ? $record->meta['geo_gap'] : [];
        $parts = array_values(array_filter([
            is_string($gap['market'] ?? null) ? $gap['market'] : null,
            is_string($gap['intent_label'] ?? null) ? $gap['intent_label'] : null,
        ]));

        return implode(' · ', $parts);
    }
}
