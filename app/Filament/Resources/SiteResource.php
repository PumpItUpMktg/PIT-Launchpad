<?php

namespace App\Filament\Resources;

use App\Branding\BrandBrief;
use App\Branding\BrandStudio;
use App\Branding\Scheme;
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
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
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
            // primary pick (Trust/Bold/Warm retired as a client-facing choice).
            Select::make('structure')->label('Layout style (auto-recommended — override optional)')
                ->options([
                    '' => 'Auto (recommended from personality)',
                    'trust' => 'Clean & established',
                    'bold' => 'Confident & dense',
                    'warm' => 'Friendly & rounded',
                ])
                ->default('')->selectablePlaceholder(false),

            Actions::make([
                Action::make('generate')->label('Generate candidates')->icon('heroicon-o-sparkles')
                    ->action(fn (Get $get, Set $set) => self::runBrandGenerate($get, $set, [])),
                Action::make('regenerate')->label('Show me 3 more')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible($hasCandidates)
                    ->action(fn (Get $get, Set $set) => self::runBrandGenerate($get, $set, self::brandAvoidFrom($get('candidates')))),
                // Re-roll the OTHER scheme, keeping personality + form (the resolved
                // structure is reused, not re-recommended).
                Action::make('flipScheme')
                    ->label(fn (Get $get): string => ($get('scheme') === 'dark' ? 'Switch to Light' : 'Switch to Dark'))
                    ->icon('heroicon-o-swatch')->color('gray')
                    ->visible($hasCandidates)
                    ->action(function (Get $get, Set $set): void {
                        $set('scheme', $get('scheme') === 'dark' ? 'light' : 'dark');
                        self::runBrandGenerate($get, $set, []);
                    }),
            ]),

            Hidden::make('candidates'),
            Hidden::make('resolved_structure'),
            Textarea::make('structure_note')->label('Structure')
                ->readOnly()->dehydrated(false)->rows(2)
                ->placeholder('Generate to see the AI structure pick.')
                ->visible($hasCandidates),

            // The picker: each option is a swatch row + a rendered component preview on
            // the candidate's own tokens/fonts/form — preview = reality. A non-native
            // Select renders the HTML option (Radio can't); the recommended candidate
            // is pre-selected, selection is Set-based.
            Select::make('selected')->label('Choose a candidate')
                ->options(fn (Get $get) => self::brandCandidateOptions($get('candidates'), (string) $get('resolved_structure')))
                ->allowHtml()->native(false)->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::fillBrandPreview($get, $set))
                ->visible($hasCandidates),

            Textarea::make('rationale')->label('Why this brand')->readOnly()->dehydrated(false)->rows(3)->visible($hasCandidates),
            Textarea::make('adjustments')->label('Validation adjustments')->readOnly()->dehydrated(false)->rows(2)->visible($hasCandidates),
        ];
    }

    /**
     * Run generation (shared by Generate + regenerate): resolve the structure (the
     * operator's pick, else the AI recommendation), generate the candidates, and
     * select the recommended one into the preview.
     *
     * @param  list<string>  $avoid
     */
    private static function runBrandGenerate(Get $get, Set $set, array $avoid): void
    {
        $answers = self::brandAnswers($get);
        $studio = app(BrandStudio::class);
        $scheme = Scheme::fromString((string) $get('scheme'));

        // Structure stays STABLE across regenerate/scheme-flip: an explicit override
        // wins; else the already-resolved one is reused; else recommend once.
        $picked = (string) $get('structure');
        $resolved = (string) $get('resolved_structure');
        if (in_array($picked, ['trust', 'bold', 'warm'], true)) {
            $structure = $picked;
            $note = 'Layout (your pick): '.ucfirst($structure).'.';
        } elseif (in_array($resolved, ['trust', 'bold', 'warm'], true)) {
            $structure = $resolved;
            $note = 'Layout: '.ucfirst($structure).' (kept).';
        } else {
            $rec = $studio->recommendStructure($answers);
            $structure = $rec->slug;
            $note = 'Layout (AI-recommended): '.ucfirst($structure).($rec->rationale !== '' ? ' — '.$rec->rationale : '').'.';
        }
        $note = ucfirst($scheme->value).' scheme · '.$note;

        $set('candidates', $studio->generateCandidates($answers, $scheme, structure: $structure, avoid: $avoid)->toArray()['candidates']);
        $set('resolved_structure', $structure);
        $set('structure_note', $note);

        // Select the recommended candidate (else the first) and fill the preview.
        $candidates = is_array($get('candidates')) ? $get('candidates') : [];
        $selected = 0;
        foreach ($candidates as $i => $candidate) {
            if (! empty($candidate['recommended'])) {
                $selected = $i;
                break;
            }
        }
        $set('selected', (string) $selected);
        self::fillBrandPreview($get, $set);

        Notification::make()->success()->title('Candidates generated')
            ->body('Pick one (the recommended is pre-selected), then Save & push.')->send();
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
    private static function brandCandidateOptions(mixed $candidates, string $structure): array
    {
        if (! is_array($candidates)) {
            return [];
        }

        $form = self::brandFormTokens($structure);
        $options = [];
        foreach ($candidates as $i => $candidate) {
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
     * Short summaries of the current candidates so a regenerate varies from them.
     *
     * @return list<string>
     */
    private static function brandAvoidFrom(mixed $candidates): array
    {
        if (! is_array($candidates)) {
            return [];
        }

        $summaries = [];
        foreach ($candidates as $candidate) {
            $palette = is_array($candidate['palette'] ?? null) ? $candidate['palette'] : [];
            $type = is_array($candidate['typography'] ?? null) ? $candidate['typography'] : [];
            $summaries[] = ($palette['primary'] ?? '?').'+'.($palette['accent'] ?? '?').' / '.($type['heading'] ?? '?');
        }

        return $summaries;
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
