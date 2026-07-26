<?php

namespace App\Reporting;

use App\Build\ProjectedServiceCleaner;
use App\ContentEngine\Review\ReviewQueue;
use App\Enums\BlogTargetStatus;
use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\PageType;
use App\Enums\SpokeGranularity;
use App\Enums\VoiceStatus;
use App\Gathering\LaunchReadiness;
use App\Models\BlogTarget;
use App\Models\Connection;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Interview;
use App\Models\Keyword;
use App\Models\KeywordCorpus;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\Source;
use App\Models\Spoke;
use App\Models\VoiceProfile;
use App\Operate\QueueHealth;
use App\Publishing\Links\InternalLinkAuditor;
use App\Publishing\OrphanScanner;
use App\Publishing\SiteContact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `launchpad:report` — a deterministic, read-only markdown snapshot of a tenant's full state across ten
 * sections (intake → anomalies). Counts before lists, every list capped, everything sorted by name/slug
 * so two consecutive reports over unchanged state diff cleanly. Changes nothing, dispatches nothing.
 *
 * Each section returns a {key, num, title, rag, lines} shape; {@see render()} assembles the header
 * (with the 5-second RAG summary) + the requested sections. Reads degrade gracefully — a missing record
 * renders as an em-dash or a zero, never a fatal.
 *
 * @phpstan-type Section array{key: string, num: int, title: string, rag: string, lines: list<string>}
 */
final class TenantReport
{
    public const SCHEMA_VERSION = '1';

    /** Section keys in emit order (the header is composed separately). */
    public const SECTIONS = ['intake', 'structure', 'pages', 'links', 'schema', 'launch', 'queue', 'engine', 'anomalies'];

    /**
     * Render the full report (or a single section when $only is one of self::SECTIONS).
     */
    public function render(Site $site, ?string $only = null, string $generatedAt = ''): string
    {
        $sections = [
            $this->intake($site),
            $this->structure($site),
            $this->pages($site),
            $this->links($site),
            $this->schema($site),
            $this->launch($site),
            $this->queue($site),
            $this->engine($site),
            $this->anomalies($site),
        ];

        if ($only !== null) {
            foreach ($sections as $section) {
                if ($section['key'] === $only) {
                    return implode("\n", $this->sectionBlock($section))."\n";
                }
            }

            return "_No section named [{$only}]. Known: ".implode(', ', self::SECTIONS)."._\n";
        }

        $out = $this->header($site, $sections, $generatedAt);
        foreach ($sections as $section) {
            $out[] = '';
            $out = array_merge($out, $this->sectionBlock($section));
        }

        return implode("\n", $out)."\n";
    }

    /** A machine-readable stub (v1): the RAG summary as JSON. Full field export lands later. */
    public function json(Site $site, string $generatedAt = ''): string
    {
        $sections = [
            $this->intake($site), $this->structure($site), $this->pages($site), $this->links($site),
            $this->schema($site), $this->launch($site), $this->queue($site), $this->engine($site), $this->anomalies($site),
        ];
        $rag = [];
        foreach ($sections as $s) {
            $rag[$s['key']] = $s['rag'];
        }

        return (string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'site_id' => $site->id,
            'brand_name' => $site->brand_name,
            'generated_at' => $generatedAt,
            'summary' => $rag,
            'note' => 'v1 stub — full per-field JSON export is not yet implemented; use the markdown report.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param Section $section @return list<string> */
    private function sectionBlock(array $section): array
    {
        return array_merge(["## {$section['num']}. {$section['title']}", ''], $section['lines']);
    }

    /**
     * @param  list<Section>  $sections
     * @return list<string>
     */
    private function header(Site $site, array $sections, string $generatedAt): array
    {
        $wp = Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword->value)->first();
        $wpStatus = $wp === null
            ? 'no WordPress connection'
            : ($wp->compromised ? 'compromised/unrotated' : 'configured')
                .($wp->last_rotated_at !== null ? ' · last rotated '.$wp->last_rotated_at->toDateString() : '');

        $summary = implode(' · ', array_map(
            fn (array $s): string => ucfirst($s['key']).' '.$s['rag'],
            $sections,
        ));

        return [
            "# Tenant report — {$site->brand_name}",
            '',
            '- Tenant id: `'.$site->id.'`',
            '- WordPress: '.Md::orDash($site->domain_url).' — '.$wpStatus,
            '- Generated at: '.Md::orDash($generatedAt).' · report schema v'.self::SCHEMA_VERSION,
            '',
            '**RAG:** '.$summary,
        ];
    }

    // ── 1. Intake & records ─────────────────────────────────────────────────

    /** @return Section */
    private function intake(Site $site): array
    {
        $lines = [];

        // data_get is null-safe both ways: it tolerates a missing branding row AND the Larastan
        // array-cast typing that fights the columns' runtime nullability.
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        $logoUrl = (string) data_get($branding, 'logo_set.url', '');

        // Business identity.
        $identity = [
            'brand name' => (string) $site->brand_name,
            'trade' => (string) (SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('trade') ?? ''),
            'corporate NAP' => (string) ($site->corporateAddressLine() ?? ''),
            'phone' => (string) $site->phone,
            'legal name' => (string) $site->legal_name,
            'logo' => $logoUrl,
        ];
        $presentIdentity = count(array_filter($identity, fn (string $v): bool => trim($v) !== ''));
        $lines[] = "**Business identity:** {$presentIdentity}/".count($identity).' present.';
        foreach ($identity as $field => $value) {
            $lines[] = '  - '.Md::tick(trim($value) !== '')." {$field}";
        }

        // Trust facts.
        $trust = [
            'license' => trim((string) $site->license_number) !== '',
            'insured' => $site->insured !== null,
            'years in business' => $site->years_in_business !== null,
            'warranty' => trim((string) $site->warranty_program) !== '',
            'guarantees' => trim((string) $site->guarantees) !== '',
        ];
        $lines[] = '';
        $lines[] = '**Trust facts:** '.count(array_filter($trust)).'/'.count($trust).' filled — '
            .implode(', ', array_map(fn (string $k, bool $v): string => Md::tick($v)." {$k}", array_keys($trust), $trust)).'.';

        // Interview.
        $interview = Interview::where('site_id', $site->id)->latest('started_at')->first();
        if ($interview === null) {
            $lines[] = '';
            $lines[] = '**Interview:** '.Md::WARN.' none started.';
        } else {
            $lines[] = '';
            $lines[] = '**Interview:** '.$interview->status->value
                .($interview->completed_at !== null ? ' · completed '.$interview->completed_at->toDateString() : '')
                .' · '.$interview->turns()->count().' turn(s).';
        }

        // Locations.
        $locations = Location::withoutGlobalScopes()->where('site_id', $site->id)
            ->orderBy('name')->get();
        $lines[] = '';
        $lines[] = "**Locations:** {$locations->count()}.";
        foreach (Md::capped($locations->map(function (Location $loc): string {
            $towns = collect($loc->served_towns);
            $resolved = $towns->filter(fn (array $t): bool => ($t['lat'] ?? null) !== null)->count();

            return '  - '.Md::orDash((string) $loc->name).' — '.($loc->is_storefront ? 'storefront' : 'service-area')
                .' · '.Md::orDash((string) $loc->phone).' · '.$towns->count().' town(s), '.$resolved.' geo-resolved'
                .' · notes '.Md::tick(trim((string) $loc->market_notes) !== '').' · place_id '.Md::tick(trim((string) $loc->place_id) !== '');
        })->all()) as $line) {
            $lines[] = $line;
        }
        $lines[] = '  - Overlapping towns across locations: '.$this->overlappingTowns($locations).' '.Md::zeroGate($this->overlappingTowns($locations));

        // Services.
        $services = Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('name')->get();
        $contaminated = app(ProjectedServiceCleaner::class)->contaminated($site)->count();
        $enrich = $this->serviceEnrichment($services);
        $lines[] = '';
        $lines[] = "**Services:** {$services->count()} · contamination (provenance-less matching structure names): {$contaminated} ".Md::zeroGate($contaminated, true);
        $lines[] = '  - Enrichment fill: symptoms '.Md::ratio($enrich['symptoms'], $services->count())
            .' · scope '.Md::ratio($enrich['scope_items'], $services->count())
            .' · process '.Md::ratio($enrich['process_steps'], $services->count())
            .' · cost '.Md::ratio($enrich['cost_factors'], $services->count())
            .' · price '.Md::ratio($enrich['price_range'], $services->count());

        // Voice + brand.
        $activeVoice = VoiceProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('status', VoiceStatus::Active->value)->exists();
        $lines[] = '';
        $lines[] = '**Voice:** '.Md::tick($activeVoice).' active profile. '
            .'**Brand:** logo '.Md::tick($logoUrl !== '')
            .' · palette '.Md::tick(! empty(data_get($branding, 'palette')))
            .' · typography '.Md::tick(! empty(data_get($branding, 'typography'))).'.';

        $rag = $presentIdentity < count($identity) || $contaminated > 0 || ! $activeVoice ? Md::WARN : Md::OK;

        return ['key' => 'intake', 'num' => 1, 'title' => 'Intake & records', 'rag' => $rag, 'lines' => $lines];
    }

    // ── 2. Structure ────────────────────────────────────────────────────────

    /** @return Section */
    private function structure(Site $site): array
    {
        $lines = [];

        $corpus = KeywordCorpus::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
        $corpusSize = (clone $corpus)->count();
        $seed = (clone $corpus)->where('source', 'seed')->count();
        $expanded = $corpusSize - $seed;
        $lines[] = "**Corpus:** {$corpusSize} term(s) — {$seed} seed, {$expanded} expanded.";
        if ($corpusSize > 0) {
            $intents = (clone $corpus)->select('intent', DB::raw('count(*) as c'))->groupBy('intent')->pluck('c', 'intent');
            $lines[] = '  - Intent: '.collect($intents)->map(fn ($c, $i): string => Md::orDash((string) $i).' '.$c)->sort()->implode(' · ');
        }

        // Silos + spoke routing (spokes carry the head term/volume/routing).
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('name')->get();
        $thin = 0;
        $siloLines = [];
        foreach ($silos as $silo) {
            $spokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('silo', $silo->name)->get();
            $targets = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('silo_id', $silo->id)->count();
            if ($targets < 3) {
                $thin++;
            }
            $own = $spokes->where('granularity', SpokeGranularity::OwnPage)->count();
            $fold = $spokes->where('granularity', SpokeGranularity::Folded)->count();
            $blog = $spokes->where('granularity', SpokeGranularity::BlogTarget)->count();
            $head = (string) $spokes->firstWhere('is_pillar', true)?->head_keyword;
            $siloLines[] = '  - '.Md::orDash((string) $silo->name).' — head '.Md::orDash($head)
                .' · '.$spokes->count().' spoke(s) ('.$own.' page / '.$fold.' fold / '.$blog.' blog) · '.$targets.' target(s)'
                .($targets < 3 ? ' '.Md::WARN.' thin' : '');
        }
        $lines[] = '';
        $lines[] = "**Silos:** {$silos->count()} · thin (<3 targets): {$thin} ".Md::zeroGate($thin, true);
        $lines = array_merge($lines, Md::capped($siloLines, 12));

        // Blueprint prune state + service mapping.
        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        $lines[] = '';
        $lines[] = '**Prune:** '.($blueprint?->confirmed_at !== null ? 'confirmed '.$blueprint->confirmed_at->toDateString() : Md::WARN.' unconfirmed')
            .($blueprint?->client_approved_at !== null ? ' · client-approved '.$blueprint->client_approved_at->toDateString() : '').'.';

        $services = Service::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('name')->get();
        $mapped = $services->whereNotNull('structure_home_cluster_id')->count();
        $unmatched = $services->whereNull('structure_home_cluster_id')->pluck('name')->sort()->values();
        $lines[] = '**Service mapping:** '.Md::ratio($mapped, $services->count()).' services have a structure home.';
        if ($unmatched->isNotEmpty()) {
            foreach (Md::capped($unmatched->map(fn ($n): string => '  - '.Md::orDash((string) $n))->all()) as $l) {
                $lines[] = $l;
            }
        }

        $rag = $thin > 0 ? Md::BAD : ($silos->isEmpty() || $blueprint?->confirmed_at === null ? Md::WARN : Md::OK);

        return ['key' => 'structure', 'num' => 2, 'title' => 'Structure', 'rag' => $rag, 'lines' => $lines];
    }

    // ── 3. Pages ────────────────────────────────────────────────────────────

    /** @return Section */
    private function pages(Site $site): array
    {
        $lines = [];
        $families = [
            'core' => fn ($q) => $q->whereNotNull('standard_type'),
            'service hubs' => fn ($q) => $q->where('page_type', PageType::Hub->value),
            'service spokes' => fn ($q) => $q->where('page_type', PageType::Service->value),
            'location' => fn ($q) => $q->where('page_type', PageType::Location->value)->whereNotNull('location_id'),
            'town' => fn ($q) => $q->where('page_type', PageType::Location->value)->whereNotNull('parent_location_id'),
        ];

        foreach ($families as $name => $scope) {
            $base = fn () => $scope(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', ContentKind::Page->value));
            $total = (clone $base())->count();
            $published = (clone $base())->where('status', ContentStatus::Published->value)->count();
            $failed = (clone $base())->whereIn('status', [ContentStatus::RenderFailed->value, ContentStatus::PublishFailed->value])->count();
            $lines[] = '**'.ucfirst($name)."** ({$total}): {$published} published, {$failed} failed"
                .($failed > 0 ? ' '.Md::WARN : '').'.';
        }

        // Spokes missing a target keyword.
        $spokesNoTarget = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('page_type', PageType::Service->value)->whereNull('target_keyword_id')->count();
        $lines[] = '';
        $lines[] = "**Spokes missing target keyword:** {$spokesNoTarget} ".Md::zeroGate($spokesNoTarget);

        // Town pages whose parent GBP page is not published (must be 0).
        $orphanTown = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('page_type', PageType::Location->value)->whereNotNull('parent_content_id')
            ->where('status', ContentStatus::Published->value)
            ->whereNotIn('parent_content_id', Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('status', ContentStatus::Published->value)->select('id'))
            ->count();
        $lines[] = "**Published town pages under an unpublished hub:** {$orphanTown} ".Md::zeroGate($orphanTown, true);

        // Territory selection.
        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
        $lines[] = '**Territory:** '.(clone $towns)->where('page_selected', true)->count().'/'.(clone $towns)->count().' towns selected.';

        // Capped non-published table.
        $pending = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->whereNotIn('status', [ContentStatus::Published->value])
            ->orderBy('slug')->get(['slug', 'status', 'page_type']);
        if ($pending->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "**Non-published pages ({$pending->count()}):**";
            foreach (Md::capped($pending->map(fn (Content $c): string => '  - `'.Md::orDash((string) $c->slug).'` — '.$c->status->value)->all()) as $l) {
                $lines[] = $l;
            }
        }

        $rag = ($spokesNoTarget > 0 || $orphanTown > 0) ? Md::WARN : Md::OK;
        if ($orphanTown > 0) {
            $rag = Md::BAD;
        }

        return ['key' => 'pages', 'num' => 3, 'title' => 'Pages', 'rag' => $rag, 'lines' => $lines];
    }

    // ── 4. Link integrity ───────────────────────────────────────────────────

    /** @return Section */
    private function links(Site $site): array
    {
        $lines = [];
        $findings = app(InternalLinkAuditor::class)->audit($site);
        $byType = [];
        foreach ($findings as $f) {
            $byType[$f->type->value][] = $f;
        }
        $dead = $byType['dead_end'] ?? [];
        $orphans = $byType['orphan'] ?? [];
        $opportunities = $byType['opportunity'] ?? [];

        $lines[] = '**Internal links:** '.count($dead).' dead-end, '.count($orphans).' orphan, '.count($opportunities).' opportunity finding(s).';
        if ($dead !== []) {
            $lines[] = '';
            $lines[] = '**Dead-ends:**';
            usort($dead, fn ($a, $b): int => strcmp($a->url, $b->url));
            foreach (Md::capped(array_map(fn ($f): string => '  - `'.Md::orDash($f->url).'` — '.Md::orDash($f->detail), $dead)) as $l) {
                $lines[] = $l;
            }
        }
        if ($orphans !== []) {
            $lines[] = '';
            $lines[] = '**Orphans (no inbound links):**';
            usort($orphans, fn ($a, $b): int => strcmp($a->url, $b->url));
            foreach (Md::capped(array_map(fn ($f): string => '  - `'.Md::orDash($f->url).'`', $orphans)) as $l) {
                $lines[] = $l;
            }
        }

        // Orphan scanner (deletion safety net) as a complementary read.
        $orphanScan = app(OrphanScanner::class)->scan($site);
        $lines[] = '';
        $lines[] = '**Deletion orphans:** '.count($orphanScan).' finding(s) (stranded-live / missing-redirect / orphaned-child).';

        $rag = ($dead !== [] || $orphans !== []) ? Md::WARN : Md::OK;

        return ['key' => 'links', 'num' => 4, 'title' => 'Link integrity', 'rag' => $rag, 'lines' => $lines];
    }

    // ── 5. Schema & NAP ─────────────────────────────────────────────────────

    /** @return Section */
    private function schema(Site $site): array
    {
        $lines = [];

        // The EXPECTED NAP record set (read-only — no page render): corporate + per-location phones.
        $phones = collect([app(SiteContact::class)->phone($site)])
            ->merge(Location::withoutGlobalScopes()->where('site_id', $site->id)->pluck('phone'))
            ->map(fn ($p): string => trim((string) $p))->filter()->unique()->sort()->values();
        $lines[] = '**NAP record set (expected distinct phone numbers):** '.$phones->count().'.';
        foreach (Md::capped($phones->map(fn ($p): string => '  - '.$p)->all()) as $l) {
            $lines[] = $l;
        }

        $storefronts = Location::withoutGlobalScopes()->where('site_id', $site->id)->where('is_storefront', true)->count();
        $lines[] = '';
        $lines[] = "**LocalBusiness expected (storefront/hybrid locations):** {$storefronts}.";
        $lines[] = '**Schema builders:** Organization (#org), Service (hub + spoke), Location (LocalBusiness + areaServed) — areaServed on location pages only (by builder design).';

        return ['key' => 'schema', 'num' => 5, 'title' => 'Schema & NAP', 'rag' => Md::OK, 'lines' => $lines];
    }

    // ── 6. Launch checklist ─────────────────────────────────────────────────

    /** @return Section */
    private function launch(Site $site): array
    {
        $lines = [];
        $checklist = app(LaunchReadiness::class)->checklist($site);
        $canLaunch = app(LaunchReadiness::class)->canLaunch($site);

        $lines[] = '**Can launch:** '.($canLaunch ? Md::OK.' yes' : Md::BAD.' no').'.';
        $lines[] = '';
        foreach ($checklist as $item) {
            $ok = $item['ok'];
            $lines[] = '  - '.Md::tick($ok).' '.Md::orDash($item['label'])
                .($item['required'] ? ' _(required)_' : ' _(advisory)_')
                .(! $ok && trim($item['detail']) !== '' ? ' — '.$item['detail'] : '');
        }

        $rag = $canLaunch ? Md::OK : Md::WARN;

        return ['key' => 'launch', 'num' => 6, 'title' => 'Launch checklist', 'rag' => $rag, 'lines' => $lines];
    }

    // ── 7. Queue & jobs ─────────────────────────────────────────────────────

    /** @return Section */
    private function queue(Site $site): array
    {
        $snapshot = app(QueueHealth::class)->snapshot();
        $lines = [
            "**Pending:** {$snapshot['pending']} · **failed:** {$snapshot['failed']} · oldest pending {$snapshot['oldest_minutes']}m"
                .($snapshot['stalled'] ? ' '.Md::WARN.' stalled' : ' '.Md::OK).'.',
        ];

        // Per-class pending breakdown (best-effort from the payload displayName), deterministic sort.
        $byClass = [];
        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            $name = json_decode((string) $payload, true)['displayName'] ?? 'unknown';
            $short = is_string($name) ? (string) (strrchr($name, '\\') ?: $name) : 'unknown';
            $short = ltrim($short, '\\');
            $byClass[$short] = ($byClass[$short] ?? 0) + 1;
        }
        if ($byClass !== []) {
            ksort($byClass);
            $lines[] = '';
            $lines[] = '**Pending by class:**';
            foreach ($byClass as $class => $count) {
                $lines[] = "  - {$class}: {$count}";
            }
        }

        $rag = $snapshot['failed'] > 0 ? Md::BAD : ($snapshot['stalled'] ? Md::WARN : Md::OK);

        return ['key' => 'queue', 'num' => 7, 'title' => 'Queue & jobs', 'rag' => $rag, 'lines' => $lines];
    }

    // ── 8. Content engine ───────────────────────────────────────────────────

    /** @return Section */
    private function engine(Site $site): array
    {
        $lines = [];

        $queued = BlogTarget::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('status', BlogTargetStatus::Queued->value)->count();
        $candidates = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::Scored->value])->count();
        $review = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->whereIn('status', ReviewQueue::statusValues())->count();
        $lines[] = "**Blog target queue:** {$queued} queued · **candidates:** {$candidates} · **in review:** {$review}.";

        // Published posts last 30 days: directed vs reactive.
        $recent = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Published->value)
            ->where('published_at', '>=', now()->subDays(30))->get(['draft_trigger']);
        $reactive = $recent->filter(fn (Content $c): bool => $c->draft_trigger === DraftTrigger::News)->count();
        $directed = $recent->count() - $reactive;
        $lines[] = '**Published posts (30d):** '.$recent->count()." — {$directed} directed, {$reactive} reactive"
            .' (configured mix: '.Md::orDash((string) $site->directed_mix).').';

        // Feeds staleness.
        $sources = Source::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('label')->get();
        if ($sources->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "**Feeds ({$sources->count()}):**";
            foreach (Md::capped($sources->map(function (Source $s): string {
                $age = $s->last_item_at !== null ? (int) $s->last_item_at->diffInDays(now()).'d ago' : 'never';
                $stale = $s->last_item_at === null || $s->last_item_at->lt(now()->subDays(14));

                return '  - '.Md::orDash((string) ($s->label ?? $s->url)).' — last item '.$age.($stale ? ' '.Md::WARN : '');
            })->all()) as $l) {
                $lines[] = $l;
            }
        }

        return ['key' => 'engine', 'num' => 8, 'title' => 'Content engine', 'rag' => Md::OK, 'lines' => $lines];
    }

    // ── 9. Anomalies ────────────────────────────────────────────────────────

    /** @return Section */
    private function anomalies(Site $site): array
    {
        $lines = [];

        $contaminated = app(ProjectedServiceCleaner::class)->contaminated($site);
        $lines[] = '**Contamination (provenance-less services matching structure names):** '.$contaminated->count().' '.Md::zeroGate($contaminated->count(), true);
        foreach (Md::capped($contaminated->pluck('name')->sort()->values()->map(fn ($n): string => '  - '.Md::orDash((string) $n))->all()) as $l) {
            $lines[] = $l;
        }

        // Posts left Uncategorized (§B stage-gate signal).
        $uncategorized = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)->where('status', ContentStatus::Published->value)
            ->whereNull('silo_id')->count();
        $lines[] = '**Live posts Uncategorized:** '.$uncategorized.' '.Md::zeroGate($uncategorized);

        $lines[] = '_Fallback-label / heading-slot warnings are logged (not persisted); not surfaced in this read-only report._';

        $rag = $contaminated->isNotEmpty() ? Md::BAD : ($uncategorized > 0 ? Md::WARN : Md::OK);

        return ['key' => 'anomalies', 'num' => 9, 'title' => 'Anomalies & warnings', 'rag' => $rag, 'lines' => $lines];
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param Collection<int, Location> $locations */
    private function overlappingTowns($locations): int
    {
        $seen = [];
        $overlap = [];
        foreach ($locations as $loc) {
            foreach (collect($loc->served_towns) as $town) {
                $name = mb_strtolower(trim((string) ($town['name'] ?? '')));
                if ($name === '') {
                    continue;
                }
                if (isset($seen[$name])) {
                    $overlap[$name] = true;
                }
                $seen[$name] = true;
            }
        }

        return count($overlap);
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return array{symptoms: int, scope_items: int, process_steps: int, cost_factors: int, price_range: int}
     */
    private function serviceEnrichment($services): array
    {
        $count = fn (string $field): int => $services->filter(fn (Service $s): bool => ! empty($s->{$field}))->count();

        return [
            'symptoms' => $count('symptoms'),
            'scope_items' => $count('scope_items'),
            'process_steps' => $count('process_steps'),
            'cost_factors' => $count('cost_factors'),
            'price_range' => $count('price_range'),
        ];
    }
}
