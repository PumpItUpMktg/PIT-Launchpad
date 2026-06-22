<?php

namespace App\Filament\Resources;

use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\ContentReviewResource\Pages\EditContentReview;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Jobs\GeneratePage;
use App\Models\Content;
use App\Models\PageConfig;
use App\Models\Scopes\SiteScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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
                TextColumn::make('slug')->label('Permalink')->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : '/'.ltrim($state, '/'))
                    ->copyable()->copyableState(fn (Content $record): string => '/'.ltrim((string) $record->slug, '/')),
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
                self::buildAction(),
                self::composerPendingAction(),
                self::reviewAction(),
                self::publishAction(),
                self::viewAction(),
                self::pageConfigAction(),
            ]);
    }

    /**
     * Per-page on-demand build (planned → building). Queues single-page generation on the worker —
     * never auto-runs the whole site. Only shown for a planned page whose composer is ready (a
     * resolvable kit); standard/hub pages without a kit get {@see composerPendingAction()} instead.
     */
    private static function buildAction(): Action
    {
        return Action::make('build')
            ->label('Build')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->visible(fn (Content $record): bool => self::isPlanned($record) && self::buildable($record))
            ->requiresConfirmation()
            ->modalDescription('Queues THIS page (kit slots from brand voice + intake grounding via Sonnet, with real internal links to your other pages, + fal image) on the worker. Only this page builds — the row shows "Generating" until it lands in Review.')
            ->action(function (Content $record): void {
                GeneratePage::enqueue($record, actorId: Auth::id());

                Notification::make()->success()
                    ->title('Queued — building on the worker')
                    ->body("'{$record->title}' is being built; it will appear in Review when ready.")
                    ->send();
            });
    }

    /**
     * The honest stub guard: a planned page whose composer isn't ready (no kit — e.g. standard /
     * hub pages on the VoiceKit seam) shows a disabled "composer pending" marker rather than
     * faking an empty draft. Wires to the real composer when it ships.
     */
    private static function composerPendingAction(): Action
    {
        return Action::make('composer_pending')
            ->label('Build unavailable — composer pending')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->disabled()
            ->visible(fn (Content $record): bool => self::isPlanned($record) && ! self::buildable($record))
            ->action(fn () => null);
    }

    /** Open the drafted page in the review queue (the existing per-page review surface). */
    private static function reviewAction(): Action
    {
        return Action::make('review')
            ->label('Review')
            ->icon('heroicon-o-eye')
            ->color('warning')
            ->visible(fn (Content $record): bool => $record->hasDraft() && $record->status !== ContentStatus::Published)
            ->url(fn (Content $record): string => EditContentReview::getUrl(['record' => $record->id]));
    }

    /** Publish THIS page to WordPress — reuses the review queue's approve → idempotent PublishContent. */
    private static function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Content $record): bool => $record->hasDraft() && $record->status !== ContentStatus::Published)
            ->action(function (Content $record): void {
                $result = app(ReviewActions::class)->approve($record, Auth::id());

                if ($result->isBlocked()) {
                    Notification::make()->danger()->title('Cannot publish')->body($result->blockedReason)->send();

                    return;
                }

                $notification = Notification::make()->success()->title('Publishing — pushed to the queue');
                if ($result->warnings !== []) {
                    $notification->body(implode(' ', $result->warnings));
                }
                $notification->send();
            });
    }

    /** View the live page (the canonical URL = the assigned permalink). */
    private static function viewAction(): Action
    {
        return Action::make('view')
            ->label('View')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->visible(fn (Content $record): bool => $record->status === ContentStatus::Published && self::liveUrl($record) !== null)
            ->url(fn (Content $record): ?string => self::liveUrl($record))
            ->openUrlInNewTab();
    }

    /** A planned page: materialized, undrafted, not currently generating — the only pre-build state. */
    private static function isPlanned(Content $record): bool
    {
        return ! $record->hasDraft() && ! $record->isGenerating();
    }

    /** The composer is ready when a wireframe kit is bound (service/location); null kit → pending. */
    private static function buildable(Content $record): bool
    {
        return $record->wireframe_kit_id !== null;
    }

    /** The live URL — site domain + the assigned permalink (the slug pushed to WordPress). */
    private static function liveUrl(Content $record): ?string
    {
        $domain = $record->site?->domain_url;
        if (! is_string($domain) || $domain === '') {
            return null;
        }

        return rtrim($domain, '/').'/'.ltrim((string) $record->slug, '/');
    }

    /**
     * Operator page-config: the page's element/slot outline (anatomy) + the
     * user-owned PageConfig inputs (never authored by AI), saved per page and
     * re-injected on every compose. No client-facing surface.
     */
    private static function pageConfigAction(): Action
    {
        return Action::make('pageConfig')
            ->label('Page config')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->modalHeading('Page config')
            ->modalDescription('User-owned settings (AI never authors these). They persist verbatim and are re-injected on every republish. Preview with: launchpad:publish-page {id} --placeholder')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (Content $record): array {
                $config = PageConfig::query()->where('content_id', $record->id)->first();

                return [
                    'hero_variant' => $config !== null ? $config->hero_variant : 'cta',
                    'form_embed' => $config?->form_embed,
                    'phone_override' => $config?->phone_override,
                    'hero_image_override' => $config?->hero_image_override,
                    'market_ref' => $config?->market_ref,
                ];
            })
            ->schema([
                Placeholder::make('outline')->label('Page anatomy (slots)')
                    ->content(fn (Content $record): HtmlString => new HtmlString(self::pageOutlineHtml($record))),
                Select::make('hero_variant')->label('Hero variant')
                    ->options(['cta' => 'CTA (default)', 'form' => 'Form'])->default('cta')->required()
                    ->helperText('Form shows the embed in a media hero; CTA keeps the standard hero.'),
                Textarea::make('form_embed')->label('Form embed')->rows(3)
                    ->placeholder('GHL form URL / embed snippet')
                    ->helperText('Wins over the site GHL config for this page. Empty → placeholder box in form hero.'),
                TextInput::make('phone_override')->label('Phone override')
                    ->placeholder('falls back to the site/location phone'),
                TextInput::make('hero_image_override')->label('Hero image override (URL)')
                    ->placeholder('falls back to the generated/default image'),
                TextInput::make('market_ref')->label('Market binding (location pages)')
                    ->placeholder('city / market — nullable'),
            ])
            ->action(function (Content $record, array $data): void {
                PageConfig::query()->updateOrCreate(
                    ['content_id' => $record->id],
                    [
                        'site_id' => $record->site_id,
                        'hero_variant' => in_array($data['hero_variant'] ?? 'cta', ['cta', 'form'], true) ? $data['hero_variant'] : 'cta',
                        'form_embed' => self::nullableString($data['form_embed'] ?? null),
                        'phone_override' => self::nullableString($data['phone_override'] ?? null),
                        'hero_image_override' => self::nullableString($data['hero_image_override'] ?? null),
                        'market_ref' => self::nullableString($data['market_ref'] ?? null),
                    ],
                );

                Notification::make()->success()->title('Page config saved')
                    ->body('Re-injected on the next republish (generated content refreshes).')->send();
            });
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /** The page's slot outline (anatomy) — each kit slot + what it currently holds. */
    private static function pageOutlineHtml(Content $record): string
    {
        $schema = $record->wireframe_kit_id !== null ? $record->wireframeKit?->schema() : null;
        if ($schema === null || $schema->slots === []) {
            return '<em>No kit schema for this page.</em>';
        }

        $payload = is_array($record->slot_payload) ? $record->slot_payload : [];
        $rows = '';
        foreach ($schema->slots as $slot) {
            $value = $payload[$slot->key] ?? null;
            $held = is_array($value)
                ? count($value).' item(s)'
                : (is_string($value) && trim($value) !== '' ? Str::limit(strip_tags($value), 60) : '<span style="opacity:.5">empty</span>');
            $card = $slot->isRepeater() ? 'repeater' : 'single';
            $rows .= '<li><code>'.e($slot->key).'</code> <span style="opacity:.6">('.e($slot->contentType->value).' · '.$card.')</span> — '.$held.'</li>';
        }

        return '<ul style="margin:0;padding-left:1.1rem;line-height:1.7">'.$rows.'</ul>';
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
