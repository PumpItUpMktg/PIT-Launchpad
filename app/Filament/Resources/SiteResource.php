<?php

namespace App\Filament\Resources;

use App\Branding\BrandBrief;
use App\Branding\BrandStudio;
use App\Branding\Scheme;
use App\Console\Commands\DeleteSiteCommand;
use App\Enums\LaunchRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\SiteStatus;
use App\Filament\Pages\Operate\OperateDashboard;
use App\Filament\Pages\SiteCockpit;
use App\Filament\Resources\SiteResource\Pages\CreateSite;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Integrations\Wordpress\WordpressException;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteNarrative;
use App\Operator\ActiveTenant;
use App\Operator\Controls\BudgetControl;
use App\Operator\Controls\CadenceControl;
use App\Operator\Controls\TemplateMapping;
use App\Operator\Handover\SiteHandover;
use App\Operator\SiteDeleter;
use App\Publishing\LaunchOrchestrator;
use App\Publishing\SitePreviewService;
use App\Security\GateCheck;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
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

    // The tenant list is operate work — it leads the Operate group in the final menu.
    protected static string|\UnitEnum|null $navigationGroup = 'Operate';

    protected static ?int $navigationSort = 0;

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
        // Card grid, not rows (menu-reorg relay): one health-faced card per tenant — name +
        // lifecycle up top, then the attention line (failures / compromised creds) and the flow
        // line (backlog / published this week). Zero-counts render muted so a red badge always
        // MEANS something. Row actions ride on each card unchanged.
        return $table
            ->columns([
                Stack::make([
                    Split::make([
                        TextColumn::make('brand_name')->label('Tenant')->weight(FontWeight::Bold)->size('lg')->searchable()->sortable(),
                        TextColumn::make('status')->badge()->sortable()->alignEnd(),
                    ]),
                    Split::make([
                        TextColumn::make('render_failed_count')->badge()->sortable()
                            ->formatStateUsing(fn (int $state): string => "{$state} render failed")
                            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                        TextColumn::make('publish_failed_count')->badge()->sortable()
                            ->formatStateUsing(fn (int $state): string => "{$state} publish failed")
                            ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                        TextColumn::make('compromised_count')->badge()->sortable()
                            ->formatStateUsing(fn (int $state): string => "{$state} creds flagged")
                            ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                    ]),
                    Split::make([
                        TextColumn::make('review_backlog_count')->badge()->sortable()
                            ->formatStateUsing(fn (int $state): string => "{$state} awaiting review")
                            ->color(fn (int $state): string => $state > 0 ? 'info' : 'gray'),
                        TextColumn::make('published_week_count')->sortable()->color('gray')
                            ->formatStateUsing(fn (int $state): string => "{$state} published this week"),
                    ]),
                ])->space(2),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->defaultSort('render_failed_count', 'desc')
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
            ])
            ->recordActions([
                self::selectTenantAction(),
                ActionGroup::make([
                    self::queueAction(),
                    self::cockpitAction(),
                    self::brandAction(),
                    self::narrativeAction(),
                    self::launchAction(),
                    self::refreshKeywordsAction(),
                    self::budgetAction(),
                    self::templatesAction(),
                    self::previewAllSectionsAction(),
                    self::handoverAction(),
                    self::deleteAction(),
                ]),
            ]);
    }

    /**
     * "Work on this tenant" — the Portfolio's primary card action. Sets the operator's active tenant
     * (the session key every Setup/Operate page reads) and enters the tenant at its Dashboard. This is
     * the switch point: return here from the topbar "Switch tenant" link and pick another card.
     */
    private static function selectTenantAction(): Action
    {
        return Action::make('selectTenant')
            ->label('Work on this')
            ->icon('heroicon-m-arrow-right-circle')
            ->button()
            ->color('primary')
            ->action(function (Site $record) {
                app(ActiveTenant::class)->set($record->id);

                return redirect(OperateDashboard::getUrl());
            });
    }

    /**
     * Permanently delete a tenant from the portfolio row — the UI surface of
     * {@see DeleteSiteCommand}, sharing {@see SiteDeleter}. Hidden for a
     * `live` (handed-over) tenant so a client site can't be removed by a misclick. The cascade
     * removes all of the site's data; WordPress is left untouched unless the operator opts in
     * (a duplicate usually shares the original's WP instance). With no other sites, the owning
     * account can be removed in the same step.
     */
    private static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete site')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Site $record): bool => $record->status !== SiteStatus::Live)
            ->requiresConfirmation()
            ->modalHeading('Delete this tenant?')
            ->modalDescription(fn (Site $record): string => self::deleteSummary($record))
            ->modalSubmitActionLabel('Delete permanently')
            ->schema([
                Toggle::make('purge_wordpress')
                    ->label('Also delete its published pages on WordPress')
                    ->helperText('Leave OFF if this WordPress install is shared with another tenant — ON would wipe the other site\'s pages too.')
                    ->default(false),
                Toggle::make('with_account')
                    ->label('Also delete the owning account if it has no other sites')
                    ->default(false),
            ])
            ->action(function (Site $record, array $data): void {
                $result = app(SiteDeleter::class)->delete(
                    $record,
                    (bool) ($data['purge_wordpress'] ?? false),
                    (bool) ($data['with_account'] ?? false),
                );

                $body = 'All of its data was removed.';
                if (! empty($result['wp_failed'])) {
                    $body .= ' ⚠ '.count($result['wp_failed']).' WordPress page(s) could not be deleted.';
                }
                if ($result['account_deleted']) {
                    $body .= ' The owning account was also removed.';
                }

                Notification::make()->success()->title("Deleted '{$record->brand_name}'")->body($body)->send();
            });
    }

    private static function deleteSummary(Site $site): string
    {
        $c = app(SiteDeleter::class)->counts($site);
        $published = count(app(SiteDeleter::class)->publishedPages($site));

        return "This permanently deletes '{$site->brand_name}' and ALL its data — "
            ."{$c['pages']} pages, {$c['posts']} posts, {$c['silos']} silos, {$c['markets']} markets, "
            ."{$c['services']} services, {$c['keywords']} keywords, its connection, brand and wizard progress. "
            ."{$published} page(s) are published on WordPress. This cannot be undone.";
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
            ->modalDescription('Answer a few questions, generate candidates, pick one, then Save & push. Save writes the brand tokens + structure the site renders with.')
            ->modalSubmitActionLabel('Save & push')
            ->fillForm(fn (Site $record): array => [
                'industry' => app(BrandStudio::class)->industryFor($record),
                'personality' => 'trustworthy',
                'scheme' => 'light',
                'structure' => '',
            ])
            ->schema(self::brandSchema())
            ->action(function (Site $record, array $data): void {
                $candidates = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];
                $chosen = $candidates[(int) ($data['selected'] ?? 0)] ?? null;

                if (! is_array($chosen)) {
                    Notification::make()->warning()->title('Nothing to save')
                        ->body('Generate candidates and pick one first.')->send();

                    return;
                }

                $palette = is_array($chosen['palette'] ?? null) ? $chosen['palette'] : [];
                $typography = is_array($chosen['typography'] ?? null) ? $chosen['typography'] : [];
                $structure = (string) ($data['resolved_structure'] ?? '');

                $studio = app(BrandStudio::class);
                $studio->save($record, $palette, $typography, $structure !== '' ? $structure : null);
                $result = $studio->push($record);

                if (! empty($result['updated'])) {
                    Notification::make()->success()->title('Brand saved & pushed')
                        ->body(sprintf(
                            '%s structure — %d brand token(s) live.',
                            ucfirst($structure ?: 'trust'),
                            (int) ($result['wf_tokens_set'] ?? 0),
                        ))->send();
                } else {
                    Notification::make()->warning()->title('Brand saved — not pushed')
                        ->body((string) ($result['error'] ?? 'Could not push to the site.'))->send();
                }
            });
    }

    /**
     * Capture the brand NARRATIVE the Core-page composer grounds on — the words §1 never captured
     * (About story, mission, values, Why-Choose-Us differentiators). Pages draft from this, never
     * fabricate around it: a blank required field holds the page "needs intake"; a blank optional
     * field is omitted, not invented. Upserts the site's {@see SiteNarrative}.
     */
    private static function narrativeAction(): Action
    {
        return Action::make('narrative')
            ->label('Brand narrative')
            ->icon('heroicon-o-document-text')
            ->modalHeading('Brand narrative')
            ->modalDescription('The words the Core-page composer grounds on. Captured here so About / Why Choose Us / Home draft from real intake — never fabricated. A blank required field holds the page "needs intake"; a blank optional field is omitted, not invented.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (Site $record): array => self::narrativeFormData($record))
            ->schema([
                Textarea::make('story')->label('Brand story (About)')->rows(5)
                    ->helperText('Required for the About page — without it, About holds "needs intake".'),
                Textarea::make('mission')->label('Mission')->rows(3),
                Repeater::make('values')->label('Values')
                    ->schema([
                        TextInput::make('title')->required(),
                        TextInput::make('description'),
                    ])->addActionLabel('Add value')->default([])->columns(2),
                Repeater::make('differentiators')->label('Differentiators (Why Choose Us)')
                    ->schema([
                        TextInput::make('title')->required(),
                        TextInput::make('description'),
                    ])->addActionLabel('Add differentiator')->default([])->columns(2)
                    ->helperText('Required for the Why Choose Us page.'),
            ])
            ->action(function (Site $record, array $data): void {
                SiteNarrative::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    ['site_id' => $record->id],
                    [
                        'story' => self::cleanNarrativeText($data['story'] ?? null),
                        'mission' => self::cleanNarrativeText($data['mission'] ?? null),
                        'values' => self::cleanNarrativeList($data['values'] ?? []),
                        'differentiators' => self::cleanNarrativeList($data['differentiators'] ?? []),
                    ],
                );

                Notification::make()->success()->title('Brand narrative saved')
                    ->body('Core pages draft from this; missing fields hold or omit rather than fabricate.')->send();
            });
    }

    /** @return array<string, mixed> */
    private static function narrativeFormData(Site $record): array
    {
        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $record->id)->first();

        return [
            'story' => $narrative?->story,
            'mission' => $narrative?->mission,
            'values' => is_array($narrative?->values) ? $narrative->values : [],
            'differentiators' => is_array($narrative?->differentiators) ? $narrative->differentiators : [],
        ];
    }

    private static function cleanNarrativeText(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private static function cleanNarrativeList(mixed $list): ?array
    {
        if (! is_array($list)) {
            return null;
        }

        $rows = array_values(array_filter(
            $list,
            fn ($row) => is_array($row) && trim((string) ($row['title'] ?? '')) !== '',
        ));

        return $rows === [] ? null : $rows;
    }

    /**
     * The brand modal: the interview (industry + personality + adjectives + optional
     * anchors + a structure pick), an inline Generate (AI structure rec + 3–5
     * candidates) and a "Show me 3 more" regenerate, then a candidate picker with a
     * read-only preview. Submit is Save & push (the selected candidate's tokens +
     * structure). All generation/validation is BrandGenerator/BrandStudio; this is
     * thin over them.
     *
     * @return list<Component>
     */
    private static function brandSchema(): array
    {
        $hasCandidates = fn (Get $get): bool => ! empty($get('candidates'));

        return [
            TextInput::make('industry')->label('Trade / industry')
                ->helperText('Pre-filled from the service catalog — refine if needed.'),

            // THE client-facing choice: Light or Dark, before generation.
            Radio::make('scheme')->label('Light or Dark?')
                ->options(['light' => 'Light', 'dark' => 'Dark'])
                ->default('light')->required()->inline()
                ->helperText('The color scheme. The layout style is recommended for you (below).'),

            Select::make('personality')->label('Brand personality')
                ->options(BrandBrief::PERSONALITIES)->required(),
            CheckboxList::make('adjectives')->label('Personality adjectives')
                ->options(BrandBrief::ADJECTIVES)->columns(2)
                ->helperText('Pick 3–5 — they refine the palette + type.'),
            TextInput::make('emotional_goal')->label('How should a visitor feel?')
                ->placeholder('e.g. confident they hired a real pro'),
            TextInput::make('color_anchors_use')->label('Colors to use (optional)')
                ->placeholder('comma-separated — existing brand colors are harmonized around'),
            TextInput::make('color_anchors_avoid')->label('Colors to avoid (optional)')
                ->placeholder('comma-separated'),
            TextInput::make('admired_brand')->label('A brand they admire (optional)'),
            // Form is AI-recommended; this is an optional advanced override, not the
            // primary pick. The layout style comes from the chosen library palette's
            // form affinity — not an operator field.

            Actions::make([
                Action::make('generate')->label('Show palettes')->icon('heroicon-o-sparkles')
                    ->action(fn (Get $get, Set $set) => self::runBrandPalettes($get, $set)),
                // Re-list the OTHER scheme's library, keeping personality (the AI
                // re-recommends within that scheme).
                Action::make('flipScheme')
                    ->label(fn (Get $get): string => ($get('scheme') === 'dark' ? 'Switch to Light' : 'Switch to Dark'))
                    ->icon('heroicon-o-swatch')->color('gray')
                    ->visible($hasCandidates)
                    ->action(function (Get $get, Set $set): void {
                        $set('scheme', $get('scheme') === 'dark' ? 'light' : 'dark');
                        self::runBrandPalettes($get, $set);
                    }),
            ]),

            Hidden::make('candidates'),
            Hidden::make('resolved_structure'),
            Textarea::make('structure_note')->label('Recommended palette')
                ->readOnly()->dehydrated(false)->rows(2)
                ->placeholder('Show palettes to see the AI recommendation.')
                ->visible($hasCandidates),

            // The picker: each option is a swatch row + a rendered component preview on
            // the candidate's own tokens/fonts/form — preview = reality. A non-native
            // Select renders the HTML option (Radio can't); the recommended candidate
            // is pre-selected, selection is Set-based.
            Select::make('selected')->label('Choose a palette')
                ->options(fn (Get $get) => self::brandCandidateOptions($get('candidates')))
                ->allowHtml()->native(false)->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::fillBrandPreview($get, $set))
                ->visible($hasCandidates),

            Textarea::make('rationale')->label('Why this brand')->readOnly()->dehydrated(false)->rows(3)->visible($hasCandidates),
            Textarea::make('adjustments')->label('Validation adjustments')->readOnly()->dehydrated(false)->rows(2)->visible($hasCandidates),
        ];
    }

    /**
     * The curated-library flow: AI recommends one set for the answers + scheme, the
     * whole scheme library is listed (recommended flagged + carrying the rationale),
     * and the recommended one is pre-selected into the preview.
     */
    private static function runBrandPalettes(Get $get, Set $set): void
    {
        $scheme = Scheme::fromString((string) $get('scheme'));
        $library = app(BrandStudio::class)->paletteCandidates(self::brandAnswers($get), $scheme);

        $set('candidates', $library->toArray()['candidates']);
        $set('resolved_structure', $library->structure); // the recommended set's form affinity

        $candidates = is_array($get('candidates')) ? $get('candidates') : [];
        $selected = 0;
        $rationale = '';
        foreach ($candidates as $i => $candidate) {
            if (! empty($candidate['recommended'])) {
                $selected = $i;
                $rationale = (string) ($candidate['rationale'] ?? '');
                break;
            }
        }
        $set('selected', (string) $selected);
        $set('structure_note', ucfirst($scheme->value).' scheme'.($rationale !== '' ? ' · '.$rationale : '').'.');
        self::fillBrandPreview($get, $set);

        Notification::make()->success()->title('Palettes ready')
            ->body('The recommended set is pre-selected — pick any, then Save & push.')->send();
    }

    /**
     * @return array<string, mixed>
     */
    private static function brandAnswers(Get $get): array
    {
        return [
            'industry' => $get('industry'),
            'personality' => $get('personality'),
            'adjectives' => $get('adjectives'),
            'emotional_goal' => $get('emotional_goal'),
            'color_anchors_use' => $get('color_anchors_use'),
            'color_anchors_avoid' => $get('color_anchors_avoid'),
            'admired_brand' => $get('admired_brand'),
        ];
    }

    /**
     * The radio option per candidate: a swatch row + a rendered component preview, on
     * the candidate's OWN tokens + fonts + the resolved form tokens. Same surface
     * model as launchpad.css (bg/text/accent/on_accent/border/bg_alt + heading font/
     * weight/transform + button radius) → preview = reality. allowHtml() renders it.
     *
     * @return array<int, string>
     */
    private static function brandCandidateOptions(mixed $candidates): array
    {
        if (! is_array($candidates)) {
            return [];
        }

        $options = [];
        foreach ($candidates as $i => $candidate) {
            // Each library palette carries its own form affinity → its own preview form.
            $form = self::brandFormTokens(is_array($candidate) ? (string) ($candidate['form'] ?? '') : '');
            $options[(int) $i] = self::brandPreviewHtml(is_array($candidate) ? $candidate : [], $form);
        }

        return $options;
    }

    /**
     * The form (structure) tokens the preview inlines — mirrors the structure bundles
     * in wireframe.css so the mini-mockup matches the live page.
     *
     * @return array{radius: string, button_radius: string, weight: string, transform: string, shadow: string}
     */
    private static function brandFormTokens(string $structure): array
    {
        return match ($structure) {
            'bold' => ['radius' => '2px', 'button_radius' => '2px', 'weight' => '800', 'transform' => 'uppercase', 'shadow' => '0 8px 24px rgba(0,0,0,.18)'],
            'warm' => ['radius' => '12px', 'button_radius' => '999px', 'weight' => '700', 'transform' => 'none', 'shadow' => '0 4px 16px rgba(0,0,0,.10)'],
            default => ['radius' => '4px', 'button_radius' => '4px', 'weight' => '600', 'transform' => 'none', 'shadow' => '0 1px 2px rgba(0,0,0,.06)'],
        };
    }

    /**
     * One candidate's swatch row + component-preview mini-mockup as inline-styled HTML.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array{radius: string, button_radius: string, weight: string, transform: string, shadow: string}  $form
     */
    private static function brandPreviewHtml(array $candidate, array $form): string
    {
        $palette = is_array($candidate['palette'] ?? null) ? $candidate['palette'] : [];
        $type = is_array($candidate['typography'] ?? null) ? $candidate['typography'] : [];

        // Hex tokens (validated upstream); fonts (catalog families). esc for safety.
        $c = fn (string $k, string $d): string => preg_replace('/[^#0-9A-Fa-f]/', '', (string) ($palette[$k] ?? $d)) ?: $d;
        $f = fn (string $k, string $d): string => htmlspecialchars((string) ($type[$k] ?? $d), ENT_QUOTES);

        [$primary, $secondary, $accent, $onAccent, $text, $muted, $bg, $bgAlt, $border] = [
            $c('primary', '#1b3a5b'), $c('secondary', '#3e6e9e'), $c('accent', '#e8a23d'), $c('on_accent', '#1a1a1a'),
            $c('text', '#1a1a1a'), $c('text_muted', '#5b6470'), $c('bg', '#ffffff'), $c('bg_alt', '#f4f6f8'), $c('border', '#e2e6eb'),
        ];
        $heading = $f('heading', 'Inter');
        $body = $f('body', 'Inter');
        $badge = ! empty($candidate['recommended'])
            ? '<span style="margin-left:8px;font-size:11px;font-weight:700;color:#b45309">★ recommended</span>'
            : '';

        $swatch = function (string $label, string $hex): string {
            return '<span title="'.$label.' '.$hex.'" style="display:inline-block;width:20px;height:20px;border-radius:4px;background:'.$hex.';border:1px solid rgba(0,0,0,.12)"></span>';
        };

        return '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;padding:4px 0">'
            .'<div style="display:flex;gap:5px">'
                .$swatch('primary', $primary).$swatch('secondary', $secondary).$swatch('accent', $accent).$swatch('bg', $bg).$swatch('text', $text)
            .'</div>'
            .'<div style="flex:1;min-width:240px;background:'.$bg.';color:'.$text.';border:1px solid '.$border.';border-radius:'.$form['radius'].';padding:14px 16px;box-shadow:'.$form['shadow'].'">'
                .'<div style="font-family:\''.$heading.'\',sans-serif;font-weight:'.$form['weight'].';text-transform:'.$form['transform'].';color:'.$text.';font-size:16px;line-height:1.2">Same-day service, guaranteed</div>'
                .'<div style="color:'.$muted.';font-size:12px;margin:3px 0 8px">Trusted local pros</div>'
                .'<p style="font-family:\''.$body.'\',sans-serif;color:'.$text.';font-size:12px;margin:0 0 10px">Fast, licensed, and fully warrantied work.</p>'
                .'<span style="display:inline-block;background:'.$accent.';color:'.$onAccent.';border-radius:'.$form['button_radius'].';padding:6px 14px;font-family:\''.$heading.'\',sans-serif;font-weight:600;font-size:12px">Get a quote</span>'
                .'<span style="display:inline-block;background:'.$bgAlt.';color:'.$muted.';border:1px solid '.$border.';border-radius:'.$form['radius'].';padding:6px 12px;margin-left:8px;font-size:11px">Why us</span>'
            .'</div>'
            .'<div style="font-size:12px;color:#6b7280;min-width:90px">'.$heading.' / '.$body.$badge.'</div>'
            .'</div>';
    }

    private static function fillBrandPreview(Get $get, Set $set): void
    {
        $candidates = is_array($get('candidates')) ? $get('candidates') : [];
        $candidate = $candidates[(int) $get('selected')] ?? null;
        if (! is_array($candidate)) {
            return;
        }

        $adjustments = is_array($candidate['adjustments'] ?? null) ? $candidate['adjustments'] : [];
        $set('rationale', (string) ($candidate['rationale'] ?? ''));
        $set('adjustments', $adjustments === [] ? 'None — all fonts and colors validated.' : implode("\n", $adjustments));
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
    /**
     * Preview the WHOLE site with every section shown — the internal-only "show all sections" control.
     * Pushes each page to WordPress as a DRAFT (data-gated sections render as clearly-labeled examples)
     * via {@see SitePreviewService}, so the operator can review the complete design on the real
     * instance. Drafts are visible to logged-in operators via the preview link, never to visitors, and
     * nothing goes live — no published page or `Content.status` is touched.
     */
    private static function previewAllSectionsAction(): Action
    {
        return Action::make('preview_all_sections')
            ->label('Preview full site')
            ->icon('heroicon-o-eye')
            ->requiresConfirmation()
            ->modalHeading('Preview the full site (all sections)')
            ->modalDescription('Pushes every page to WordPress as a DRAFT with every section shown — data-gated sections (reviews, guarantee, certifications, …) render as clearly-labeled examples. Internal-only: drafts are visible to logged-in operators via the preview link, never to visitors, and nothing goes live.')
            ->modalSubmitActionLabel('Preview full site')
            ->action(function (Site $record): void {
                $results = app(SitePreviewService::class)->previewSite($record);

                $ready = count(array_filter($results, fn (array $r): bool => $r['result']->isReady()));
                $skipped = count(array_filter($results, fn (array $r): bool => $r['result']->state === 'unavailable'));
                $failed = count($results) - $ready - $skipped;

                $body = trim(($skipped > 0 ? "{$skipped} skipped (not generated yet). " : '')
                    .($failed > 0 ? "{$failed} failed to push." : 'All sections shown; nothing went live.'));

                $notification = Notification::make()->title("Previewed {$ready} page(s) as internal drafts")->body($body);
                $failed > 0 ? $notification->warning() : $notification->success();
                $notification->send();
            });
    }

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

    /** The per-site pipeline drill-down (funnel, per-silo volume, failure counts) — re-linked here after the card redesign orphaned it. */
    private static function cockpitAction(): Action
    {
        return Action::make('cockpit')
            ->label('Pipeline cockpit')
            ->icon('heroicon-o-chart-bar')
            ->url(fn (Site $record): string => SiteCockpit::getUrl(['site' => $record->id]));
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
        // The on-ramp to the unified onboarding flow: capture the business basics only — the
        // WordPress connection is now the guided flow's Step 2 (Connect WordPress) and the brand
        // its Step 3, so creation can't get ahead of a prepped site. CreateSite drops the operator
        // into Step 1 after save.
        return $schema->components([
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
                ->helperText('Where the site lives — you\'ll connect WordPress next.'),
            Select::make('status')
                ->options(self::statusOptions())
                ->default(SiteStatus::Onboarding->value)
                ->required(),
        ]);
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
