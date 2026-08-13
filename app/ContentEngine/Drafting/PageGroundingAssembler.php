<?php

namespace App\ContentEngine\Drafting;

use App\Build\Permalinks;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Local\Grounding\LocationGrounding;
use App\Models\Content;
use App\Models\Location;
use App\Models\Market;
use App\Models\Offer;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use App\Models\WireframeKit;
use App\PageBuilder\Schema\KitSchema;
use App\Publishing\PhoneNumber;
use App\Standard\StandardPageIntake;
use App\Support\BusinessHours;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Builds the page grounding from the site's intake entities (services scoped to
 * the page's silo; offers/markets/proof/branding at the honest site level — the
 * "do not invent locality beyond what grounding supplies" rule). All reads bypass
 * the site global scope and filter on site_id explicitly, so assembly is safe on
 * the worker / console where CurrentSite isn't resolved. A page without a kit is
 * a setup error surfaced as a draft failure (the engine guard wraps the throw).
 */
class PageGroundingAssembler
{
    public function __construct(
        private readonly VoiceResolver $voice = new VoiceResolver,
        private readonly Permalinks $permalinks = new Permalinks,
        private readonly LocationGrounding $grounding = new LocationGrounding,
    ) {}

    public function assemble(Content $page): PageGrounding
    {
        $siteId = (string) $page->site_id;
        $kit = $this->kit($page);
        $services = $this->groundingServices($page);
        $voice = $this->voice->active($siteId);

        // The page is referral-mode when its SUBJECT service is: the pinned service for a spoke, else the
        // silo's pillar (falling back to the first). Mirrors the render/schema subject-service resolution.
        $subject = $page->primary_service_id !== null
            ? $services->first()
            : ($services->first(fn (Service $s): bool => $s->silo_role === ServiceSiloRole::Pillar) ?? $services->first());
        $referral = (bool) $subject?->referral_mode;

        return new PageGrounding(
            kit: $kit,
            pageType: $page->page_type ?? PageType::Service,
            voiceProfile: $this->voice->toArray($voice),
            voiceProfileVersion: $voice !== null ? (int) $voice->version : 0,
            services: $services->map(fn (Service $s) => $this->serviceArray($s))->values()->all(),
            problems: $this->problems($services),
            offers: $this->offers($siteId),
            proof: $this->proof($siteId),
            markets: $this->markets($siteId, $page),
            branding: $this->branding($siteId, $page),
            targetKeyword: $this->targetKeyword($page),
            relatedLinks: $this->relatedLinks($page),
            pageLabel: $page->standard_type?->label(),
            narrative: $this->narrative($page),
            facts: $this->facts($page),
            location: $this->location($page),
            siblingHeadings: $this->siblingHeadings($page),
            referralMode: $referral,
        );
    }

    /**
     * The section H2s already drafted on OTHER service/hub pages of this site — fed to the drafter as
     * "do not duplicate," so no two pages share an identical drafted heading (the site-wide uniqueness
     * acceptance for drafted H2s). Only heading slots (keys ending in `_heading`) from real slot
     * payloads count; capped to keep the prompt bounded. Empty for the first page drafted on a site.
     *
     * @return list<string>
     */
    private function siblingHeadings(Content $page): array
    {
        $siblings = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('page_type', [PageType::Service->value, PageType::Hub->value])
            ->where('id', '!=', $page->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'slot_payload']);

        $headings = [];
        foreach ($siblings as $sibling) {
            $payload = is_array($sibling->slot_payload) ? $sibling->slot_payload : [];
            foreach ($payload as $key => $value) {
                if (! str_ends_with((string) $key, '_heading')) {
                    continue;
                }
                $text = trim(is_array($value) ? (string) ($value[0] ?? '') : (string) $value);
                if ($text !== '') {
                    // De-dupe case-insensitively, keep first casing; cap the total for prompt bounds.
                    $headings[mb_strtolower($text)] ??= $text;
                }
            }
            if (count($headings) >= 40) {
                break;
            }
        }

        return array_values($headings);
    }

    /**
     * A LOCATION page's subject block — prompt-ready local facts, grounded on the physical §1 Location
     * the page belongs to. Two shapes, both pinned to a real record so the drafter can never wander to
     * another region:
     *  - a HUB page pinned via `location_id` — its subject IS that Location (its own city).
     *  - a TOWN page pinned via `parent_location_id` — its subject is the TOWN itself (parsed from the
     *    page title, e.g. "Edison, NJ"), grounded with the PARENT location's regional facts (phone,
     *    category, served towns, local facts). This is what stops an NJ town page from drafting off a
     *    different region's market.
     * The operator's market_notes ride VERBATIM; the grounded facts come from the trade-keyed
     * {@see LocationGrounding} pipeline (never a blocker — a failure just means fewer facts). Empty for
     * non-location pages and unpinned (market-era) location pages.
     *
     * @return array<string, mixed>
     */
    private function location(Content $page): array
    {
        if ($page->page_type !== PageType::Location) {
            return [];
        }

        // Hub page pinned to its own §1 Location — subject is that location.
        if ($page->location_id !== null) {
            return $this->locationFacts($page, (string) $page->location_id, null);
        }

        // Town page pinned to its parent GBP Location — subject is the TOWN, grounded on the parent.
        if ($page->parent_location_id !== null) {
            return $this->locationFacts($page, (string) $page->parent_location_id, $this->townSubject($page));
        }

        return [];
    }

    /**
     * Resolve a physical §1 Location into the prompt's local-facts block. `$subject` (a town page's own
     * `city`/`state`, parsed from its title) OVERRIDES the resolved location's city so the page is about
     * the town, not the parent's city — while the parent still supplies the honest regional context.
     *
     * @param  array{city: string, state: string}|null  $subject
     * @return array<string, mixed>
     */
    private function locationFacts(Content $page, string $locationId, ?array $subject): array
    {
        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->find($locationId);
        if ($location === null) {
            return [];
        }

        ['city' => $city, 'state' => $state] = $location->cityState();

        $towns = [];
        foreach ($location->served_towns ?? [] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $towns[] = $name;
            }
        }

        // The grounded local_facts are fetched for THIS physical location (its city + region) and cached
        // on it, so they describe the PARENT's own city. That is right for a HUB page (its subject IS that
        // city) but wrong for a TOWN page: injecting Doylestown's regional facts into a Buckingham page is
        // exactly what drifted the copy to Allentown / generic tri-state filler. A town page therefore
        // grounds on its OWN town only (name + honest served-area context) and skips the parent's regional
        // facts — until per-town grounding exists, honest-by-omission beats wrong-region color.
        $isTown = $subject !== null;
        $facts = [];
        if (! $isTown) {
            $trade = SiloBlueprint::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $page->site_id)
                ->value('trade');

            try {
                $grounded = $this->grounding->for($location, is_string($trade) ? $trade : null);
                $facts = $grounded['facts'];
            } catch (\Throwable) {
                $facts = []; // grounding is color, never a blocker
            }
        }

        // A town page's subject is the town itself: its own city/state lead, the parent supplies context.
        $subjectCity = $subject !== null && trim($subject['city']) !== '' ? trim($subject['city']) : ($city !== '' ? $city : trim((string) $location->name));
        $subjectState = $subject !== null && trim($subject['state']) !== '' ? trim($subject['state']) : $state;

        return array_filter([
            'city' => $subjectCity,
            'state' => $subjectState,
            'phone' => (string) PhoneNumber::display(trim((string) $location->phone)),
            'primary_category' => trim((string) $location->primary_category),
            'served_towns' => $towns,
            'market_notes' => trim((string) $location->market_notes),
            'local_facts' => $facts,
        ], fn ($v) => $v !== '' && $v !== []);
    }

    /**
     * A town page's own town, parsed from its title ("Edison, NJ" → city "Edison", state "NJ"). The
     * title is the town's authored name; the trailing ", ST" is the state when present.
     *
     * @return array{city: string, state: string}
     */
    private function townSubject(Content $page): array
    {
        $title = trim((string) $page->title);
        if (preg_match('/^(.*?),\s*([A-Za-z]{2})\.?$/', $title, $m) === 1) {
            return ['city' => trim($m[1]), 'state' => strtoupper($m[2])];
        }

        return ['city' => $title, 'state' => ''];
    }

    /**
     * Honest operational facts from §1 — the only operational claims a draft may make (emergency
     * availability, real business hours, which contact channels exist). An FAQ answering "do you
     * handle emergencies?" or "when are you open?" reads the truth here; an absent fact is simply
     * not present, so the drafter has nothing to invent from (degrade by omission).
     *
     * @return array<string, mixed>
     */
    private function facts(Content $page): array
    {
        $site = $page->site;
        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->orderBy('created_at')
            ->first();

        $facts = [];
        if ($site !== null) {
            $facts['offers_emergency_service'] = (bool) $site->offers_emergency;
        }

        $hours = [];
        foreach (BusinessHours::fromStored(is_array($location?->hours) ? $location->hours : null) as $row) {
            if ($row['all_day']) {
                $hours[$row['day']] = 'open 24 hours';
            } elseif (! $row['closed'] && trim((string) $row['open']) !== '') {
                $hours[$row['day']] = trim((string) $row['open']).'–'.trim((string) $row['close']);
            }
        }
        if ($hours !== []) {
            $facts['business_hours'] = $hours;
        }

        $channels = array_keys(array_filter([
            'phone' => trim((string) ($location->phone ?? $site->phone ?? '')) !== '',
            'email' => trim((string) ($location->email ?? '')) !== '',
        ]));
        if ($channels !== []) {
            $facts['contact_channels'] = $channels;
        }

        return $facts;
    }

    /**
     * The captured brand-narrative intake this page composes from — ONLY the fields its kit consumes
     * AND that carry real content. Absent fields are left out entirely, so the drafter has nothing to
     * fabricate from and the kit's intake-bound slots condition out (degrade by omission).
     *
     * @return array<string, mixed>
     */
    private function narrative(Content $page): array
    {
        $type = $page->standard_type;
        if ($type === null) {
            return [];
        }

        $fields = StandardPageIntake::fields($type);
        if ($fields === []) {
            return [];
        }

        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->first();

        $out = [];
        foreach ($fields as $field) {
            if (StandardPageIntake::present($narrative, $field)) {
                $out[$field] = $narrative?->getAttribute($field);
            }
        }

        return $out;
    }

    /**
     * Real internal-link targets: the permalinks of the site's OTHER materialized pages. The full
     * URL map exists from materialize, so the drafter writes links to final URLs (not placeholders)
     * even if the target page isn't built yet — it self-heals as the inventory completes. Capped to
     * keep the prompt bounded. (Best-effort: site-wide for now; silo-scoped ordering lands with the
     * Spoke→§1 bridge.)
     *
     * @return list<array{anchor: string, path: string}>
     */
    private function relatedLinks(Content $page): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('id', '!=', $page->id)
            ->orderBy('title')
            ->limit(50)
            ->get()
            ->map(fn (Content $p) => ['anchor' => (string) $p->title, 'path' => $this->permalinks->path($p)])
            ->values()
            ->all();
    }

    private function kit(Content $page): KitSchema
    {
        $kit = $page->wireframe_kit_id !== null ? WireframeKit::find($page->wireframe_kit_id) : null;
        $schema = $kit?->schema();

        if ($schema === null) {
            throw new RuntimeException("Page [{$page->id}] has no resolvable wireframe kit — assign a kit before generating.");
        }

        return $schema;
    }

    /**
     * The services that ground the page's content. A service page pinned to its own subject
     * (`primary_service_id`) grounds on THAT service alone — never the silo's siblings, which is the
     * cluster-bleed that let a /toilet-replacement page draft from a sibling's (slow-drain /
     * sewer-backup) copy. Everything else (hub/category pages, location pages, or pages predating the
     * pin) keeps the silo-scoped / site-wide fallback.
     *
     * @return Collection<int, Service>
     */
    private function groundingServices(Content $page): Collection
    {
        $siteId = (string) $page->site_id;

        if ($page->primary_service_id !== null) {
            $own = Service::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)
                ->whereKey($page->primary_service_id)
                ->with('problems')
                ->get();

            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return $this->services($siteId, $page->silo_id !== null ? (string) $page->silo_id : null);
    }

    /**
     * @return Collection<int, Service>
     */
    private function services(string $siteId, ?string $siloId): Collection
    {
        $query = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->with('problems');

        if ($siloId !== null) {
            $scoped = (clone $query)
                ->whereHas('silos', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->whereKey($siloId))
                ->get();

            if ($scoped->isNotEmpty()) {
                return $scoped;
            }
        }

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceArray(Service $service): array
    {
        // Enrichment (hub+spoke relay): the record fields the spoke drafter frames copy around.
        // The price range rides ONLY when real — the drafter may reference it honestly; absent
        // means the cost copy is factors-only (never an invented number).
        $range = is_array($service->price_range) ? $service->price_range : [];
        $hasRange = is_numeric($range['low'] ?? null) && is_numeric($range['high'] ?? null);

        return array_filter([
            'name' => $service->name,
            'description' => $service->description,
            'short_description' => $service->short_description,
            'scope' => $service->scope,
            'pricing_posture' => $service->pricing_posture,
            'primary_cta_intent' => $service->primary_cta_intent,
            'geo_applicability' => $service->geo_applicability,
            'symptoms' => $service->symptoms,
            'scope_items' => $service->scope_items,
            'process_steps' => $service->process_steps,
            'cost_factors' => $service->cost_factors,
            'price_range' => $hasRange ? $range : null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return list<array<string, mixed>>
     */
    private function problems(Collection $services): array
    {
        $problems = [];
        foreach ($services as $service) {
            foreach ($service->problems as $problem) {
                $problems[] = ['phrase' => $problem->phrase, 'intent' => $problem->intent];
            }
        }

        return $problems;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function offers(string $siteId): array
    {
        return Offer::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get()
            ->map(fn (Offer $o) => ['name' => $o->name, 'terms' => $o->terms])
            ->values()
            ->all();
    }

    /**
     * The substantiated proof pool — the only business facts a page may assert.
     *
     * @return list<Claim>
     */
    private function proof(string $siteId): array
    {
        return ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('is_substantiated', true)
            ->get()
            ->map(fn (ProofItem $item) => Claim::fromProofItem($item))
            ->all();
    }

    /**
     * The site's markets — a location page's OWN town (its pinned `market_id`) leads the list as the
     * page's subject; the rest follow as service-area context. So the drafter writes about Clifton,
     * not a sibling town, without losing the area list a town page legitimately needs.
     *
     * @return list<array<string, mixed>>
     */
    private function markets(string $siteId, Content $page): array
    {
        // GEO-NEUTRAL service layer (hub+spoke relay): service-family pages must never receive
        // locality — the drafter's markets block then explicitly instructs "do not name any town".
        // Geo belongs exclusively to location pages (and the brand-level home/utility pages keep
        // their honest coverage context).
        if (in_array($page->page_type, [PageType::Service, PageType::Hub, PageType::Pillar, PageType::Cluster], true)) {
            return [];
        }

        // A TOWN page (pinned to a parent GBP location, no own location_id) grounds its geography on the
        // parent-location LOCATION block — its subject town + the parent's honest served area. Dumping
        // the site-wide market list here is what let a multi-region tenant draft an NJ town off a PA
        // market. So a town page rides ONLY its own market as coverage color (none if it has no matching
        // market row — the LOCATION block still carries the town).
        if ($page->page_type === PageType::Location && $page->location_id === null && $page->parent_location_id !== null) {
            $own = $page->market_id !== null
                ? Market::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->whereKey($page->market_id)->first()
                : null;

            return $own === null ? [] : [$this->marketArray($own)];
        }

        $markets = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get();

        if ($page->market_id !== null) {
            $markets = $markets
                ->sortByDesc(fn (Market $m) => (string) $m->id === (string) $page->market_id ? 1 : 0)
                ->values();
        }

        return $markets->map(fn (Market $m) => $this->marketArray($m))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function marketArray(Market $m): array
    {
        return [
            'name' => $m->name,
            'region' => $m->region,
            'neighborhoods' => $m->neighborhoods,
            'local_nuances' => $m->local_nuances,
            'is_covered' => (bool) $m->is_covered,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branding(string $siteId, Content $page): array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->first();

        return [
            'brand_name' => $page->site?->brand_name,
            'entity_type' => $branding?->entity_type,
            'nap' => $branding?->canonical_nap,
        ];
    }

    private function targetKeyword(Content $page): ?string
    {
        return $page->targetKeyword()->withoutGlobalScope(SiteScope::class)->first()?->query;
    }
}
