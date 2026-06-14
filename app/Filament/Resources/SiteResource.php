<?php

namespace App\Filament\Resources;

use App\Branding\BrandBrief;
use App\Branding\BrandStudio;
use App\Branding\FontCatalog;
use App\Enums\LaunchRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\SiteStatus;
use App\Filament\Resources\SiteResource\Pages\CreateSite;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Integrations\Wordpress\WordpressException;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Site;
use App\Operator\Controls\BudgetControl;
use App\Operator\Controls\CadenceControl;
use App\Operator\Controls\TemplateMapping;
use App\Operator\Controls\WordpressConnector;
use App\Operator\Handover\SiteHandover;
use App\Publishing\LaunchOrchestrator;
use App\Security\GateCheck;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Portfolio triage — the operator home. Lists every tenant with at-a-glance
 * health (review backlog, job failures, recent publishing, §9 compromised
 * credentials), click-through into the tenant's review queue, and the §9-gated
 * handover (→ Live) action. Operator-only (the whole panel is).
 */
class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Portfolio';

    protected static ?int $navigationSort = -2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'contents as review_backlog_count' => fn (Builder $q) => $q->where('status', 'needs_review'),
            'contents as render_failed_count' => fn (Builder $q) => $q->where('status', 'render_failed'),
            'contents as publish_failed_count' => fn (Builder $q) => $q->where('status', 'publish_failed'),
            'contents as published_week_count' => fn (Builder $q) => $q->where('status', 'published')
                ->where('published_at', '>=', now()->startOfWeek()),
            'connections as compromised_count' => fn (Builder $q) => $q->where('compromised', true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->copyable()->fontFamily('mono')->size('xs')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('brand_name')->label('Tenant')->searchable()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('review_backlog_count')->label('Backlog')->badge()->sortable(),
                TextColumn::make('published_week_count')->label('Published/wk')->sortable(),
                TextColumn::make('render_failed_count')->label('Render fail')->badge()
                    ->color('danger')->sortable(),
                TextColumn::make('publish_failed_count')->label('Publish fail')->badge()
                    ->color('danger')->sortable(),
                TextColumn::make('compromised_count')->label('Compromised creds')->badge()
                    ->color('warning')->sortable(),
            ])
            ->defaultSort('render_failed_count', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::queueAction(),
                    self::brandAction(),
                    self::launchAction(),
                    self::refreshKeywordsAction(),
                    self::budgetAction(),
                    self::templatesAction(),
                    self::handoverAction(),
                ]),
            ]);
    }

    /**
     * C5 capture — generate a brand for the tenant. A short interview (industry
     * pre-filled from the Service Catalog, an operator-editable trade) → the AI
     * BrandGenerator → a review/adjust screen (palette swatches + font pickers +
     * rationale + the validation guard's adjustments) → Save & push, which writes
     * SiteBranding and pushes it into the Elementor Global Kit (the proven #105
     * path). Works for new and existing tenants — no active wizard required.
     */
    private static function brandAction(): Action
    {
        return Action::make('brand')
            ->label('Generate brand')
            ->icon('heroicon-o-swatch')
            ->modalHeading('Generate brand')
            ->modalDescription('Answer a few questions, generate, then review and adjust before saving. Save pushes the brand into this site\'s Elementor Global Kit.')
            ->modalSubmitActionLabel('Save & push')
            ->fillForm(fn (Site $record): array => [
                'industry' => app(BrandStudio::class)->industryFor($record),
                'personality' => 'trustworthy',
            ])
            ->schema(self::brandSchema())
            ->action(function (Site $record, array $data): void {
                $palette = array_filter([
                    'primary' => $data['primary'] ?? null,
                    'accent' => $data['accent'] ?? null,
                    'text' => $data['text'] ?? null,
                ], fn ($v) => is_string($v) && $v !== '');

                $typography = array_filter([
                    'heading' => $data['heading_font'] ?? null,
                    'body' => $data['body_font'] ?? null,
                ], fn ($v) => is_string($v) && $v !== '');

                if ($palette === [] && $typography === []) {
                    Notification::make()->warning()->title('Nothing to save')
                        ->body('Generate a brand first, then save.')->send();

                    return;
                }

                $studio = app(BrandStudio::class);
                $studio->save($record, $palette, $typography);
                $result = $studio->push($record);

                if (! empty($result['updated'])) {
                    Notification::make()->success()->title('Brand saved & pushed')
                        ->body(sprintf(
                            'Global Kit #%d — %d color(s), %d font(s).',
                            (int) ($result['kit_id'] ?? 0),
                            (int) ($result['colors_set'] ?? 0),
                            (int) ($result['fonts_set'] ?? 0),
                        ))->send();
                } else {
                    Notification::make()->warning()->title('Brand saved — not pushed')
                        ->body((string) ($result['error'] ?? 'Could not push to the site.'))->send();
                }
            });
    }

    /**
     * The brand modal: the interview, an inline Generate action that fills the
     * review fields below, and the editable review (palette pickers + catalog-backed
     * font selects + rationale + guard adjustments). The submit is Save & push.
     *
     * @return list<Component>
     */
    private static function brandSchema(): array
    {
        $fonts = collect(app(FontCatalog::class)->all())->mapWithKeys(fn (string $f) => [$f => $f])->all();

        return [
            TextInput::make('industry')->label('Trade / industry')
                ->helperText('Pre-filled from the service catalog — refine if needed.'),
            Select::make('personality')->label('Brand personality')
                ->options(BrandBrief::PERSONALITIES)->required(),
            TextInput::make('emotional_goal')->label('How should a visitor feel?')
                ->placeholder('e.g. confident they hired a real pro'),
            TextInput::make('color_anchors_use')->label('Colors to use (optional)')
                ->placeholder('comma-separated'),
            TextInput::make('color_anchors_avoid')->label('Colors to avoid (optional)')
                ->placeholder('comma-separated'),
            TextInput::make('admired_brand')->label('A brand they admire (optional)'),

            Actions::make([
                Action::make('generate')
                    ->label('Generate brand')
                    ->icon('heroicon-o-sparkles')
                    ->action(function (Get $get, Set $set): void {
                        $brand = app(BrandStudio::class)->generateFromAnswers([
                            'industry' => $get('industry'),
                            'personality' => $get('personality'),
                            'emotional_goal' => $get('emotional_goal'),
                            'color_anchors_use' => $get('color_anchors_use'),
                            'color_anchors_avoid' => $get('color_anchors_avoid'),
                            'admired_brand' => $get('admired_brand'),
                        ]);

                        $set('primary', $brand->palette['primary']);
                        $set('accent', $brand->palette['accent']);
                        $set('text', $brand->palette['text']);
                        $set('heading_font', $brand->typography['heading']);
                        $set('body_font', $brand->typography['body']);
                        $set('rationale', $brand->rationale);
                        $set('adjustments', $brand->adjustments === []
                            ? 'None — all fonts and colors validated.'
                            : implode("\n", $brand->adjustments));

                        Notification::make()->success()->title('Brand generated')
                            ->body('Review and adjust below, then Save & push.')->send();
                    }),
            ]),

            ColorPicker::make('primary')->label('Primary color'),
            ColorPicker::make('accent')->label('Accent color'),
            ColorPicker::make('text')->label('Text color'),
            Select::make('heading_font')->label('Heading font')->options($fonts)->searchable(),
            Select::make('body_font')->label('Body font')->options($fonts)->searchable(),
            Textarea::make('rationale')->label('Why this brand')
                ->readOnly()->dehydrated(false)->rows(3)
                ->placeholder('Generate to see the rationale.'),
            Textarea::make('adjustments')->label('Validation adjustments')
                ->readOnly()->dehydrated(false)->rows(2)
                ->placeholder('Any font/color the guard corrected will show here.'),
        ];
    }

    /**
     * §7b(c) — map each pushed kit to one of this site's Elementor templates. The
     * inventory is fetched LIVE from the site (so the operator maps with eyes,
     * against templates that actually exist), with a preview link per template; an
     * explicit mapping overrides the kit's elementor_template_ref suggestion and is
     * stored engine-side (versioned). The §2 push stamps the resolved template on
     * the /content blob.
     */
    private static function templatesAction(): Action
    {
        return Action::make('templates')
            ->label('Templates')
            ->icon('heroicon-o-rectangle-group')
            ->modalDescription('Map each kit to one of this site\'s Elementor templates (fetched live). An explicit mapping overrides the kit\'s suggested template.')
            ->schema(fn (Site $record): array => self::templateSchema($record))
            ->action(function (Site $record, array $data): void {
                $service = app(TemplateMapping::class);

                $selections = [];
                foreach ($service->kits($record) as $kit) {
                    $key = self::templateFieldKey($kit['kit']);
                    if (array_key_exists($key, $data)) {
                        $value = $data[$key];
                        $selections[$kit['kit']] = ($value === null || $value === '') ? null : (int) $value;
                    }
                }

                $applied = $service->applyMappings($record, $selections, self::templateTitles($record));

                Notification::make()->success()->title('Template mappings saved')->body($applied.' change(s).')->send();
            });
    }

    /**
     * A stable, Livewire-safe field key for a kit name (kit names carry hyphens).
     */
    private static function templateFieldKey(string $kit): string
    {
        return 'tpl_'.preg_replace('/[^a-z0-9_]/i', '_', $kit);
    }

    /**
     * Best-effort id→title cache from the live inventory (for the stored display
     * cache); empty when the site is unreachable.
     *
     * @return array<int, string>
     */
    private static function templateTitles(Site $site): array
    {
        try {
            $inventory = app(TemplateMapping::class)->inventory($site);
        } catch (WordpressException) {
            return [];
        }

        $titles = [];
        foreach ($inventory as $t) {
            $titles[(int) ($t['id'] ?? 0)] = (string) ($t['title'] ?? '');
        }

        return $titles;
    }

    /**
     * Build the mapping form: a preview list of the site's templates (with preview
     * links) + one Select per pushed kit, defaulted to its current mapping. Returns
     * a single explanatory field when the site is unreachable, has no templates, or
     * has no pushed kits — never a half-rendered form.
     *
     * @return list<Component>
     */
    private static function templateSchema(Site $record): array
    {
        $service = app(TemplateMapping::class);

        try {
            $inventory = $service->inventory($record);
        } catch (WordpressException $e) {
            return [Placeholder::make('templates_error')->label('Site unreachable')
                ->content('Could not read templates from this site: '.$e->getMessage())];
        }

        if ($inventory === []) {
            return [Placeholder::make('templates_empty')
                ->content('This site has no Elementor saved templates yet.')];
        }

        $options = [];
        $previewLines = [];
        foreach ($inventory as $t) {
            $id = (int) ($t['id'] ?? 0);
            $title = (string) ($t['title'] ?? ('#'.$id));
            $type = (string) ($t['type'] ?? '');
            $options[$id] = $title.($type !== '' ? " ({$type})" : '');

            $preview = (string) ($t['preview_url'] ?? '');
            $previewLines[] = $preview !== ''
                ? e($title).' — <a href="'.e($preview).'" target="_blank" rel="noopener">preview</a>'
                : e($title);
        }

        $kits = $service->kits($record);
        if ($kits === []) {
            return [Placeholder::make('templates_no_kits')
                ->content('No kits are pushed for this site yet — publish a page first.')];
        }

        $current = $service->current($record);

        $fields = [Placeholder::make('templates_preview')->label('Templates on this site')
            ->content(new HtmlString(implode('<br>', $previewLines)))];

        foreach ($kits as $kit) {
            $name = $kit['kit'];
            $fields[] = Select::make(self::templateFieldKey($name))
                ->label($name.($kit['version'] !== null ? ' v'.$kit['version'] : ''))
                ->options($options)
                ->default($current[$name]->template_id ?? null)
                ->placeholder('— use kit suggestion ('.($kit['suggestion'] ?? 'none').') —')
                ->helperText('Default suggestion: '.($kit['suggestion'] ?? 'none'));
        }

        return $fields;
    }

    /**
     * Launch site — push the full built site (silos → content → redirects) to its
     * connected WordPress instance through the plugin contract. Idempotent (edited
     * pages are skipped, never clobbered) and gated on a present, non-compromised
     * WordPress connection; the run is recorded as the go-live audit.
     */
    private static function launchAction(): Action
    {
        return Action::make('launch')
            ->label('Launch site')
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->modalDescription('Pushes this site\'s silos, content and redirects to its connected WordPress instance. Safe to re-run — pages edited in WordPress are skipped, not overwritten.')
            ->action(function (Site $record): void {
                $run = app(LaunchOrchestrator::class)->launch($record, Auth::id());

                if ($run->status === LaunchRunStatus::Blocked) {
                    Notification::make()->danger()
                        ->title('Launch blocked')
                        ->body('No present, non-compromised WordPress connection. Wire one via Connections → Connect WordPress site.')
                        ->send();

                    return;
                }

                $notification = Notification::make()->title('Launch complete — '.$run->summary());
                ($run->failed > 0 ? $notification->warning() : $notification->success())->send();
            });
    }

    /**
     * Operator "refresh now" — drive §5 discovery + position tracking for this
     * site immediately, bypassing the cadence window, and report inline. Spends
     * DataForSEO calls, so it confirms before running.
     */
    private static function refreshKeywordsAction(): Action
    {
        return Action::make('refresh_keywords')
            ->label('Refresh §5')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('Runs keyword discovery + position tracking now (bypasses the cadence window). Spends DataForSEO calls.')
            ->action(function (Site $record): void {
                $result = app(SitePipelineRefresher::class)->refresh($record, PipelineTrigger::Manual, force: true);

                Notification::make()->success()
                    ->title('§5 refresh complete')
                    ->body(sprintf(
                        'Discovery: %s (%d scored) · Tracking: %s (%d snapshots).',
                        $result->discoveryRan ? 'ran' : 'skipped',
                        $result->keywordsScored,
                        $result->trackingRan ? 'ran' : 'skipped',
                        $result->snapshots,
                    ))
                    ->send();
            });
    }

    private static function queueAction(): Action
    {
        return Action::make('queue')
            ->label('Review queue')
            ->icon('heroicon-o-inbox-stack')
            ->url(fn (): string => ContentReviewResource::getUrl('index'));
    }

    /**
     * The §5 budget ceiling (editable) + read-only usage, with the cadence tier
     * degradation order surfaced (C dropped first under the ceiling).
     */
    private static function budgetAction(): Action
    {
        return Action::make('budget')
            ->label('Budget & cadence')
            ->icon('heroicon-o-banknotes')
            ->fillForm(fn (Site $record): array => ['budget_ceiling' => app(BudgetControl::class)->ceiling($record)])
            ->modalDescription(fn (Site $record): string => self::cadenceSummary($record))
            ->schema([
                TextInput::make('budget_ceiling')
                    ->label('Sampling budget ceiling (units/period)')
                    ->numeric()
                    ->helperText('Usage-against-budget is read-only — metered billing is deferred.'),
            ])
            ->action(function (Site $record, array $data): void {
                $ceiling = $data['budget_ceiling'] !== null && $data['budget_ceiling'] !== ''
                    ? (int) $data['budget_ceiling'] : null;
                app(BudgetControl::class)->setCeiling($record, $ceiling);
                Notification::make()->success()->title('Budget updated')->send();
            });
    }

    private static function cadenceSummary(Site $site): string
    {
        $budget = app(BudgetControl::class);
        $cadence = app(CadenceControl::class);

        $usage = $budget->usage($site);
        $ceiling = $budget->ceiling($site);
        $order = implode(' → ', array_map(fn (array $t): string => strtoupper($t['tier']), $cadence->tiers()));

        return "Usage this period: {$usage}".($ceiling !== null ? " / {$ceiling}" : ' (no ceiling set)')
            ."\nCadence degrades tiers in order: {$order} (dropped-first → kept-last).";
    }

    private static function handoverAction(): Action
    {
        return Action::make('handover')
            ->label('Hand over → Live')
            ->icon('heroicon-o-rocket-launch')
            ->visible(fn (Site $record): bool => $record->status !== SiteStatus::Live)
            ->modalDescription(fn (Site $record): string => self::gateSummary($record))
            ->schema([
                Select::make('mode')
                    ->required()
                    ->default('stays')
                    ->options([
                        'stays' => 'Stays on our hosting (no re-point)',
                        'migrate' => 'Migrate to client hosting (re-point + verify)',
                    ]),
                TextInput::make('new_url')->label('New site URL')->url()
                    ->helperText('Required for migrate-to-client-hosting.'),
                TextInput::make('new_app_password')->label('New WP application password')->password(),
                TextInput::make('username')->label('WP username (optional)'),
            ])
            ->action(function (Site $record, array $data): void {
                $handover = app(SiteHandover::class);

                if (($data['mode'] ?? 'stays') === 'migrate') {
                    $url = (string) ($data['new_url'] ?? '');
                    $password = (string) ($data['new_app_password'] ?? '');
                    if ($url === '' || $password === '') {
                        Notification::make()->danger()->title('Re-point needs a URL and app password')->send();

                        return;
                    }
                    $result = $handover->handoverMigrating($record, $url, $password, $data['username'] ?? null, Auth::id());
                } else {
                    $result = $handover->handoverStaying($record, Auth::id());
                }

                if ($result->launched) {
                    Notification::make()->success()->title('Handed over — site is Live')->body($result->message)->send();

                    return;
                }

                Notification::make()->danger()
                    ->title('Blocked by the launch gate')
                    ->body($result->gateResult !== null ? self::failureList($result->gateResult->failures()) : $result->message)
                    ->send();
            });
    }

    private static function gateSummary(Site $site): string
    {
        $result = app(SiteHandover::class)->gate($site);

        if ($result->passed) {
            return 'All credential checks pass — ready to hand over.';
        }

        return "The launch gate is red. Clear these first:\n".self::failureList($result->failures());
    }

    /**
     * @param  list<GateCheck>  $failures
     */
    private static function failureList(array $failures): string
    {
        return implode("\n", array_map(
            fn (GateCheck $c) => "• {$c->label}: {$c->reason}",
            $failures,
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (SiteStatus::cases() as $status) {
            $options[$status->value] = ucfirst($status->value);
        }

        return $options;
    }

    /**
     * The New Site Wizard — stand up a complete, publishable tenant in two steps,
     * no tinker: (1) the Site itself, led with one line defining what a site IS so
     * the operator doesn't spin up a site per location; (2) the WordPress
     * connection, verify-before-store, so content can publish immediately. The §7a
     * onboarding wizard is the full brand intake; this is the operator's quick
     * create. (Sites have no scalar slug column — `domain_url` is the wireable
     * "where it lives" field; `slug_conventions` is a separate content-slug pattern
     * set during intake. The WordPress fields are NOT Site columns — CreateSite's
     * handleRecordCreation peels them off and wires the Connection.)
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('Site')
                    ->icon('heroicon-o-building-office-2')
                    ->description('One brand, one WordPress install')
                    ->schema([
                        Text::make('A site is one WordPress install — one brand\'s website. Locations (offices, service areas) live inside a site; don\'t create a separate site per location.'),
                        Select::make('account_id')
                            ->label('Account (brand)')
                            ->relationship('account', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')->label('Account name')->required(),
                            ]),
                        TextInput::make('brand_name')
                            ->label('Site name')
                            ->required(),
                        TextInput::make('legal_name')
                            ->label('Legal name')
                            ->helperText('Optional — the registered business name, if it differs from the brand.'),
                        TextInput::make('domain_url')
                            ->label('Site URL')
                            ->url()
                            ->placeholder('https://client-site.com')
                            ->helperText('Where the site lives — the WordPress base URL this site publishes to.'),
                        Select::make('status')
                            ->options(self::statusOptions())
                            ->default(SiteStatus::Onboarding->value)
                            ->required(),
                    ]),
                Step::make('WordPress connection')
                    ->icon('heroicon-o-globe-alt')
                    ->description('Wire publishing now (optional)')
                    ->schema([
                        Text::make('Connect the WordPress install so this site can publish with zero tinker. The credential is verified against the live site before it is saved. Leave the password blank to wire it later under Controls → Connections.'),
                        TextInput::make('base_url')
                            ->label('WordPress base URL')
                            ->url()
                            ->placeholder('https://client-site.com')
                            ->helperText('Defaults to the Site URL above when left blank.'),
                        TextInput::make('username')
                            ->label('WP username')
                            ->default('launchpad-sync'),
                        TextInput::make('app_password')
                            ->label('Application password')
                            ->password()
                            ->revealable()
                            ->helperText('Generated for the launchpad-sync user (provider = WordPress).'),
                        Actions::make([
                            self::testConnectionAction(),
                        ]),
                    ]),
            ]),
        ]);
    }

    /**
     * "Test connection" — ping the entered WordPress credentials against the live
     * site and report green/red inline, WITHOUT creating anything. Lets the
     * operator confirm the connection in the panel before finishing the wizard
     * (the runbook's step-2 green check), keeping the zero-tinker path honest.
     */
    private static function testConnectionAction(): Action
    {
        return Action::make('test_connection')
            ->label('Test connection')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->action(function (Get $get): void {
                $baseUrl = trim((string) $get('base_url')) ?: trim((string) $get('domain_url'));
                $password = trim((string) $get('app_password'));

                if ($baseUrl === '' || $password === '') {
                    Notification::make()->warning()
                        ->title('Enter the WordPress URL and application password first')->send();

                    return;
                }

                $ok = app(WordpressConnector::class)->verify([
                    'base_url' => $baseUrl,
                    'username' => trim((string) $get('username')) ?: 'launchpad-sync',
                    'app_password' => $password,
                ]);

                $ok
                    ? Notification::make()->success()->title('Connection verified')
                        ->body('WordPress authenticated — green to finish the wizard.')->send()
                    : Notification::make()->danger()->title('Connection failed')
                        ->body('WordPress did not authenticate at '.$baseUrl.'/wp-json/wp/v2/users/me — check the URL and app password.')->send();
            });
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
        ];
    }
}
