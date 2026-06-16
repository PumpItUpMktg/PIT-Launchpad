<?php

namespace App\Filament\Resources;

use App\Enums\ContentKind;
use App\Enums\UserRole;
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
                        GeneratePage::enqueue($record, actorId: Auth::id());

                        Notification::make()->success()
                            ->title('Queued — generating on the worker')
                            ->body("'{$record->title}' is being drafted; it will appear in Review when ready.")
                            ->send();
                    }),
                self::pageConfigAction(),
            ]);
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
