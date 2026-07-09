<?php

namespace App\Publishing\Blocks;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\ProofType;
use App\Enums\StandardPageType;
use App\Enums\VoiceStatus;
use App\Models\Content;
use App\Models\Location;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteNarrative;
use App\Models\VoiceProfile;
use App\Publishing\Legal\LegalContext;
use App\Publishing\Legal\LegalTemplates;
use App\Publishing\MetaBlobAssembler;
use App\Publishing\SiteContact;
use App\Support\BusinessHours;

/**
 * Resolves a page's real §1/§4 inputs and composes its `post_content` (core Gutenberg block markup) —
 * the wire between {@see MetaBlobAssembler} (which owns slot/image resolution) and the
 * {@see BlockPageComposer}. Kept separate from the composer so the composer stays pure/testable; kept
 * separate from MetaBlobAssembler so the Elementor path is untouched while the block path is proven.
 *
 * Returns null for page types whose block pattern hasn't shipped yet — the blob then simply carries no
 * `post_content` and the plugin (Layer 5) falls back to the existing render for those pages.
 */
final class BlockContentAssembler
{
    public function __construct(
        private readonly BlockPageComposer $composer,
        private readonly ServiceAreaResolver $serviceAreas,
        private readonly ServiceCardBlurb $cardBlurb,
        private readonly SiteContact $contact,
    ) {}

    /**
     * @param  array<string, mixed>  $slots  the resolved slot_payload (from MetaBlobAssembler)
     * @param  array<string, array<string, mixed>>  $images  the resolved image map
     * @param  bool  $preview  operator proof-view context — build all sections with labeled placeholders
     *                         for missing data (see {@see BlockPageComposer::composeHome}); publish omits.
     * @param  bool  $mapAvailable  whether the tenant has service-area map geometry (resolved once by the
     *                              caller and carried on the blob) — drives the interactive areas map.
     */
    public function compose(Content $content, array $slots, array $images, bool $preview = false, bool $mapAvailable = false): ?string
    {
        if ($content->kind !== ContentKind::Page) {
            return null;
        }

        $ctx = $this->context($this->site($content));

        // Dispatch by the page's identity: Home by page_type, standard pages (Utility page_type) by
        // their finer standard_type. A type whose block composer hasn't shipped returns null — the blob
        // then carries no post_content and the plugin falls back to the existing render.
        if ($content->page_type === PageType::Home) {
            return $this->composeHome($content, $slots, $images, $ctx, $preview, $mapAvailable);
        }

        if ($content->page_type === PageType::Service) {
            return $this->composeService($content, $slots, $images, $ctx, $preview);
        }

        if ($content->standard_type === StandardPageType::WhyChooseUs) {
            return $this->composeWhyChooseUs($content, $slots, $images, $ctx, $preview);
        }

        if ($content->standard_type === StandardPageType::About) {
            return $this->composeAbout($content, $slots, $images, $ctx, $preview);
        }

        if ($content->standard_type === StandardPageType::Faq) {
            return $this->composer->composeFaq($slots, $ctx, $this->faqItems($slots), $preview);
        }

        if ($content->standard_type === StandardPageType::AreasWeServe) {
            $siteId = (string) $content->site_id;

            return $this->composer->composeAreas(
                slots: $slots,
                ctx: $ctx,
                counties: $this->serviceAreas->resolve($siteId)['counties'],
                byCounty: $this->serviceAreas->byCounty($siteId),
                preview: $preview,
                mapAvailable: $mapAvailable,
            );
        }

        if ($content->standard_type === StandardPageType::Contact) {
            return $this->composeContact($content, $slots, $ctx, $preview);
        }

        if ($content->standard_type === StandardPageType::Privacy || $content->standard_type === StandardPageType::Terms) {
            return $this->composeLegal($content, $slots);
        }

        return null;
    }

    /**
     * The Contact page — hero + the real NAP (phone via the shared context, email/address/hours from the
     * primary §1 Location) + a request-service CTA. Every channel is honest and per-field data-gated.
     *
     * @param  array<string, mixed>  $slots
     */
    private function composeContact(Content $content, array $slots, PageContext $ctx, bool $preview): string
    {
        $location = $this->primaryLocation($content);

        return $this->composer->composeContact(
            slots: $slots,
            ctx: $ctx,
            email: is_string($location?->email) && trim($location->email) !== '' ? trim($location->email) : null,
            address: is_string($location?->address) && trim($location->address) !== '' ? trim($location->address) : null,
            hours: $this->businessHours($location),
            preview: $preview,
        );
    }

    /**
     * The primary location's hours as display rows — one per open day (closed days drop, so the block
     * shows only real hours, never a wall of "Closed"). Empty when nothing is captured.
     *
     * @return list<array{label: string, value: string}>
     */
    private function businessHours(?Location $location): array
    {
        $hours = is_array($location?->hours) ? $location->hours : null;

        $labels = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

        $rows = [];
        foreach (BusinessHours::fromStored($hours) as $row) {
            if ($row['all_day']) {
                $value = 'Open 24 hours';
            } elseif ($row['closed'] || trim((string) $row['open']) === '') {
                continue; // drop closed days — show only the days the business is actually open
            } else {
                $value = trim((string) $row['open']).' – '.trim((string) $row['close']);
            }
            $rows[] = ['label' => $labels[$row['day']] ?? ucfirst($row['day']), 'value' => $value];
        }

        return $rows;
    }

    /**
     * A legal page — template-driven, never AI-generated (fabricated legal terms are false
     * representations). The {@see LegalTemplates} boilerplate is filled with the tenant's REAL data
     * (business name, site host, captured contact channels) and stamped with a stable effective date
     * (the page's creation date), so the same page always renders the same document.
     *
     * @param  array<string, mixed>  $slots
     */
    private function composeLegal(Content $content, array $slots): string
    {
        $site = $this->site($content);
        $brand = trim((string) ($site->brand_name ?? ''));

        $ctx = new LegalContext(
            business: $brand !== '' ? $brand : 'this business',
            siteUrl: $site?->domain_url,
            email: $this->legalContactEmail($content),
            phone: $site !== null ? $this->contact->phone($site) : null,
            effectiveDate: $content->created_at?->format('F j, Y') ?? '',
        );

        $templates = new LegalTemplates;
        $doc = $content->standard_type === StandardPageType::Terms
            ? $templates->terms($ctx)
            : $templates->privacy($ctx);

        return $this->composer->composeLegal($slots, $doc['title'], $doc['effective_date'], $doc['sections']);
    }

    /** The tenant's primary contact email (the earliest Location's email), or null — never invented. */
    private function legalContactEmail(Content $content): ?string
    {
        $email = $this->primaryLocation($content)?->email;

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }

    /**
     * The drafted FAQ question/answer pairs from the resolved `faq` slot (drafter-generated, shaped to
     * {question, answer} by the SlotShaper). Only complete pairs render; capped. Same key the plugin's
     * FAQPage schema reads, so accordion + schema stay in lockstep.
     *
     * @param  array<string, mixed>  $slots
     * @return list<array{question: string, answer: string}>
     */
    private function faqItems(array $slots): array
    {
        $raw = $slots['faq'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? $item['q'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? $item['a'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $out[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return array_slice($out, 0, 12);
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeHome(Content $content, array $slots, array $images, PageContext $ctx, bool $preview, bool $mapAvailable): string
    {
        $site = $this->site($content);
        $siteId = (string) $content->site_id;

        return $this->composer->composeHome(
            slots: $slots,
            images: $images,
            serviceCards: $this->serviceCards($content, $site),
            ctx: $ctx,
            trustStats: $this->trustStats($content),
            differentiators: $this->differentiators($content),
            testimonials: $this->testimonials($content),
            serviceAreaCounties: $this->serviceAreas->resolve($siteId)['counties'],
            serviceAreasByCounty: $this->serviceAreas->byCounty($siteId),
            processSteps: $this->processSteps($content),
            certifications: $this->mergedCredentials($content),
            guarantee: $this->guarantee($content),
            preview: $preview,
            serviceAreaMapAvailable: $mapAvailable,
        );
    }

    /**
     * A SERVICE page — the Elementor→blocks migration's first non-standard page type. Resolves the
     * drafted body slots (overview prose, feature lines, grounded "why us") + §1 proof (stats, process,
     * reviews) + the real NAP, and hands them to {@see BlockPageComposer::composeService}. The features
     * slot arrives SlotShaper-flattened to plain strings; the prose slots are cleaned to paragraphs the
     * same way About's story is (drafter HTML stripped). Everything data-gates — nothing is fabricated.
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeService(Content $content, array $slots, array $images, PageContext $ctx, bool $preview): string
    {
        $location = $this->primaryLocation($content);

        // The problem→solution explainer: both drafted body slots, each cleaned to plain-text paragraphs
        // (the drafter may emit HTML — solution_overview is rich_text), then concatenated in reading order.
        $overview = array_merge(
            $this->storyParagraphs($this->slotString($slots, 'problem_explainer')),
            $this->storyParagraphs($this->slotString($slots, 'solution_overview')),
        );

        return $this->composer->composeService(
            slots: $slots,
            images: $images,
            ctx: $ctx,
            features: $this->stringList($slots, 'service_features'),
            overview: $overview,
            whyUs: $this->storyParagraphs($this->slotString($slots, 'why_us')),
            trustStats: $this->trustStats($content),
            processSteps: $this->processSteps($content),
            testimonials: $this->testimonials($content),
            faqs: $this->faqItems($slots),
            email: is_string($location?->email) && trim($location->email) !== '' ? trim($location->email) : null,
            address: is_string($location?->address) && trim($location->address) !== '' ? trim($location->address) : null,
            hours: $this->businessHours($location),
            preview: $preview,
        );
    }

    /**
     * A list slot's values as clean plain strings — the SlotShaper flattens a `list` slot to a plain
     * array of strings, but read defensively (an item may still arrive as a {text/label} map). Trimmed,
     * empties dropped, order preserved.
     *
     * @param  array<string, mixed>  $slots
     * @return list<string>
     */
    private function stringList(array $slots, string $key): array
    {
        $raw = $slots[$key] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $value = is_array($item)
                ? trim((string) ($item['text'] ?? $item['label'] ?? $item['value'] ?? ''))
                : trim((string) $item);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** The tenant's primary (earliest) §1 Location, tenant-scoped, or null. */
    private function primaryLocation(Content $content): ?Location
    {
        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * The dedicated Why Choose Us page — its body is the §1 differentiators, reinforced by the
     * guarantee, real credentials, and substantiated client voice (all reusing the home resolvers).
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeWhyChooseUs(Content $content, array $slots, array $images, PageContext $ctx, bool $preview): string
    {
        return $this->composer->composeWhyChooseUs(
            slots: $slots,
            images: $images,
            ctx: $ctx,
            differentiators: $this->differentiators($content),
            guarantee: $this->guarantee($content),
            certifications: $this->mergedCredentials($content),
            testimonials: $this->testimonials($content),
            trustStats: $this->trustStats($content),
            preview: $preview,
        );
    }

    /**
     * The About page — the trust-conversion brand narrative. Story renders the DRAFTED, voice-expanded
     * prose, falling back to the client's OWN §1 story (their words, prose by nature). The MISSION
     * renders ONLY the drafted slot — the raw intake field is an operator brief (often keywords), and
     * rendering it verbatim was the canonical raw-intake leak; an ungenerated page omits the band
     * rather than leaking the brief. Values prefer the drafted promise-framed lines (falling back to
     * the client's short §1 labels); differentiators reuse the home why-us cards; the credibility
     * badges are AUDIENCE-ordered (commercial leads certifications, homeowner leads reviews). About is
     * brand-critical and review-gated, so the voice-expanded prose is operator-checked before publish.
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     */
    private function composeAbout(Content $content, array $slots, array $images, PageContext $ctx, bool $preview): string
    {
        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->first();

        $site = $this->site($content);

        return $this->composer->composeAbout(
            slots: $slots,
            images: $images,
            ctx: $ctx,
            story: $this->storyParagraphs($this->slotString($slots, 'our_story') ?: (string) ($narrative->story ?? '')),
            mission: $this->slotString($slots, 'mission'),
            values: $this->aboutValues($slots, $narrative),
            differentiators: $this->differentiators($content),
            team: $this->teamMembers($narrative),
            credibilityBadges: $this->credibilityBadges($content, audience: $this->audience($content)),
            trustStats: $this->trustStats($content),
            brand: trim((string) ($site->brand_name ?? '')),
            preview: $preview,
        );
    }

    /**
     * The About values — the DRAFTED promise-framed lines when the page has been generated (the
     * drafter phrases each captured value as a client promise), else the client's own short §1 labels
     * ({title, description}, safe display text — unlike the mission's long-text brief). A drafted line
     * may arrive as "Title — line" / "Title: line" or as a {title, description} map.
     *
     * @param  array<string, mixed>  $slots
     * @return list<array{title: string, description: string}>
     */
    private function aboutValues(array $slots, ?SiteNarrative $narrative): array
    {
        $raw = $slots['values'] ?? [];
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (is_array($item)) {
                $title = trim((string) ($item['title'] ?? ''));
                if ($title !== '') {
                    $out[] = ['title' => $title, 'description' => trim((string) ($item['description'] ?? $item['text'] ?? ''))];
                }

                continue;
            }

            $line = trim((string) $item);
            if ($line === '') {
                continue;
            }
            // "Title — line" / "Title: line" → {title, description}; a bare line is title-only.
            $parts = preg_split('/\s+—\s+|\s+–\s+|:\s+/', $line, 2) ?: [$line];
            $out[] = ['title' => trim($parts[0]), 'description' => trim($parts[1] ?? '')];
        }

        if ($out !== []) {
            return array_slice($out, 0, 6);
        }

        return $this->titleDescList(is_array($narrative?->values) ? $narrative->values : []);
    }

    /**
     * The site's primary audience, resolved from the active voice profile's interview — 'commercial'
     * when the captured audience reads business-facing, else 'homeowner' (the residential default).
     * Drives EMPHASIS ONLY (which trust signals lead); data still decides what renders.
     */
    private function audience(Content $content): string
    {
        $voice = VoiceProfile::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('status', VoiceStatus::Active->value)
            ->first();

        $primary = (string) data_get($voice?->audience, 'primary', '');

        return preg_match('/commercial|business|facilit|property manager|office|industrial|building owner/i', $primary) === 1
            ? 'commercial'
            : 'homeowner';
    }

    /**
     * Split brand-story prose (possibly the drafter's HTML) into clean plain-text paragraphs — the
     * block composer emits each as its own <p>. Explicit paragraph breaks (</p> or blank lines) win;
     * remaining tags are stripped so nothing invalid reaches the block markup.
     *
     * @return list<string>
     */
    private function storyParagraphs(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $chunks = preg_split('#</p>|\n\s*\n#i', $raw) ?: [$raw];

        $out = [];
        foreach ($chunks as $chunk) {
            $text = trim(html_entity_decode(strip_tags((string) $chunk), ENT_QUOTES, 'UTF-8'));
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Normalize a {title, description} list (values / differentiators) to the section shape, capped.
     *
     * @param  array<int, mixed>  $items
     * @return list<array{title: string, description: string}>
     */
    private function titleDescList(array $items, int $cap = 6): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $out[] = ['title' => $title, 'description' => trim((string) ($item['description'] ?? ''))];
        }

        return array_slice($out, 0, $cap);
    }

    /**
     * The tenant's real team from §1 — {name, role, bio, photo_url}, verbatim. Only members with a name
     * appear; never fabricated. (No drafted team slot exists — the team is structured intake.)
     *
     * @return list<array{name: string, role: string, bio: string, photo_url: string}>
     */
    private function teamMembers(?SiteNarrative $narrative): array
    {
        $items = is_array($narrative?->team) ? $narrative->team : [];

        $out = [];
        foreach ($items as $member) {
            if (! is_array($member)) {
                continue;
            }
            $name = trim((string) ($member['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'role' => trim((string) ($member['role'] ?? $member['title'] ?? '')),
                'bio' => trim((string) ($member['bio'] ?? $member['description'] ?? '')),
                'photo_url' => trim((string) ($member['photo_url'] ?? $member['photo'] ?? '')),
            ];
        }

        return array_slice($out, 0, 8);
    }

    /** First scalar of a slot value (single slots may arrive as a string or a one-element array). */
    private function slotString(array $slots, string $key): string
    {
        $value = $slots[$key] ?? '';
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return trim((string) $value);
    }

    /**
     * The shared page context — phone resolves via SiteContact (primary Location's number, else the
     * site business phone), so a guided-onboarded tenant that set only the wizard phone still shows a
     * number. Emergency uses the dedicated after-hours line when set, else the main number.
     */
    private function context(?Site $site): PageContext
    {
        $phone = $site !== null ? $this->contact->phone($site) : null;
        $emergencyPhone = $site !== null ? $this->contact->emergencyPhone($site) : null;

        return new PageContext(
            phoneDisplay: $phone,
            phoneTel: $this->contact->tel($phone),
            emergency: $site !== null && (bool) $site->offers_emergency,
            emergencyDisplay: $emergencyPhone,
            emergencyTel: $this->contact->tel($emergencyPhone),
        );
    }

    /**
     * The tenant's real "how it works" — ProofItem type=process (title + description). Descriptive, not
     * a claim, so substantiation isn't required. Fewer than 2 real steps → [] so the section keeps its
     * safe business-agnostic default rather than a lopsided one.
     *
     * @return list<array{title: string, description: string}>
     */
    private function processSteps(Content $content): array
    {
        $items = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('type', ProofType::Process->value)
            ->orderBy('created_at')
            ->limit(3)
            ->get();

        $steps = [];
        foreach ($items as $item) {
            $title = $this->payloadString($item, ['title', 'label']);
            $description = $this->payloadString($item, ['description', 'text']);
            if ($title !== '' && $description !== '') {
                $steps[] = ['title' => $title, 'description' => $description];
            }
        }

        return count($steps) >= 2 ? $steps : [];
    }

    /**
     * Credibility badges — substantiated licenses / certs / awards / affiliations / warranties only.
     * Each item's short label becomes a badge; never fabricated, capped so the strip stays a strip.
     *
     * With an $audience, the badge ORDER follows who the page is convincing (and review aggregates
     * join the pool): a homeowner is moved by social proof first (reviews → guarantees → licensed);
     * a commercial buyer by qualifications first (certifications → licenses → track record). Audience
     * sets the intended emphasis only — data still decides what actually renders (a homeowner site
     * with no reviews simply leads with its next available signal).
     *
     * @param  'homeowner'|'commercial'|null  $audience  null = the default created-at order (home / why-us callers)
     * @return list<string>
     */
    private function credibilityBadges(Content $content, ?string $audience = null): array
    {
        $types = [
            ProofType::License->value, ProofType::Cert->value, ProofType::Award->value,
            ProofType::Affiliation->value, ProofType::Warranty->value, ProofType::Guarantee->value,
        ];
        if ($audience !== null) {
            $types[] = ProofType::ReviewAggregate->value; // reviews belong in About's trust order
        }

        $items = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('is_substantiated', true)
            ->whereIn('type', $types)
            ->orderBy('created_at')
            ->get();

        if ($audience !== null) {
            $priority = $audience === 'commercial'
                ? [ProofType::Cert->value, ProofType::License->value, ProofType::Award->value, ProofType::Affiliation->value, ProofType::Warranty->value, ProofType::Guarantee->value, ProofType::ReviewAggregate->value]
                : [ProofType::ReviewAggregate->value, ProofType::Guarantee->value, ProofType::Warranty->value, ProofType::License->value, ProofType::Cert->value, ProofType::Affiliation->value, ProofType::Award->value];

            $items = $items->sortBy(function (ProofItem $item) use ($priority): int {
                $rank = array_search($item->type->value, $priority, true);

                return $rank === false ? count($priority) : $rank;
            })->values();
        }

        $badges = [];
        foreach ($items as $item) {
            $label = $this->payloadString($item, ['label', 'text']);
            if ($label !== '') {
                $badges[] = $label;
            }
            if (count($badges) === 4) {
                break;
            }
        }

        return $badges;
    }

    /**
     * Why-Choose-Us differentiators from the site narrative — real captured value props only.
     *
     * @return list<array{title: string, description: string}>
     */
    private function differentiators(Content $content): array
    {
        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->first();

        $items = is_array($narrative?->differentiators) ? $narrative->differentiators : [];

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $out[] = ['title' => $title, 'description' => trim((string) ($item['description'] ?? ''))];
        }

        return array_slice($out, 0, 6);
    }

    /**
     * The tenant's guarantee/warranty from the narrative — {name, description}, verbatim. Empty when
     * none is captured (the band then omits). Never fabricated.
     *
     * @return array{name: string, description: string}
     */
    private function guarantee(Content $content): array
    {
        $g = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->value('guarantee');
        $g = is_array($g) ? $g : [];

        return ['name' => trim((string) ($g['name'] ?? '')), 'description' => trim((string) ($g['description'] ?? ''))];
    }

    /**
     * The tenant's certifications/credentials from the narrative — a list of {label, number?, logo_url?},
     * verbatim, per-item. Only captured credentials appear (never fabricated); capped so the row stays a
     * row.
     *
     * @return list<array{label: string, number: string, logo_url: string}>
     */
    private function certifications(Content $content): array
    {
        $items = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->value('certifications');
        $items = is_array($items) ? $items : [];

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $logo = trim((string) ($item['logo_url'] ?? ''));
            if ($label === '' && $logo === '') {
                continue;
            }
            $out[] = ['label' => $label, 'number' => trim((string) ($item['number'] ?? '')), 'logo_url' => $logo];
        }

        return array_slice($out, 0, 6);
    }

    /**
     * The single credentials band's items — the tenant's captured {@see certifications()} PLUS their
     * substantiated proof {@see credibilityBadges()} (licenses / certs / ratings), folded in as
     * label-only credentials and DEDUPED against the captured certs by label. This unifies what used to
     * be two overlapping bands (the credibility strip + the certifications row) into one, so Home and
     * Why-Choose-Us never show the same trust signals twice. Verbatim; never fabricated; capped.
     *
     * @return list<array{label: string, number: string, logo_url: string}>
     */
    private function mergedCredentials(Content $content): array
    {
        $certs = $this->certifications($content);

        $seen = [];
        foreach ($certs as $cert) {
            $seen[mb_strtolower($cert['label'])] = true;
        }

        foreach ($this->credibilityBadges($content) as $badge) {
            $key = mb_strtolower(trim($badge));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $certs[] = ['label' => $badge, 'number' => '', 'logo_url' => ''];
        }

        return array_slice($certs, 0, 8);
    }

    /**
     * Testimonials — substantiated review / testimonial proof items only. Payload is freeform, so read
     * the conventional keys defensively; a quote is required, author/role/stars are optional.
     *
     * @return list<array{quote: string, author: string, role: string, stars: int}>
     */
    private function testimonials(Content $content): array
    {
        $items = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('is_substantiated', true)
            ->whereIn('type', [ProofType::Testimonial->value, ProofType::ReviewAggregate->value])
            ->orderBy('created_at')
            ->limit(3)
            ->get();

        $quotes = [];
        foreach ($items as $item) {
            $quote = $this->payloadString($item, ['quote', 'text', 'label']);
            if ($quote === '') {
                continue;
            }
            $payload = is_array($item->payload) ? $item->payload : [];
            $stars = (int) ($payload['stars'] ?? $payload['rating'] ?? 0);
            $quotes[] = [
                'quote' => $quote,
                'author' => $this->payloadString($item, ['author', 'name']),
                'role' => $this->payloadString($item, ['role', 'source', 'company']),
                'stars' => max(0, min(5, $stars)),
            ];
        }

        return $quotes;
    }

    /**
     * First non-empty value among the given payload keys of a proof item (payload is freeform JSON).
     *
     * @param  list<string>  $keys
     */
    private function payloadString(ProofItem $item, array $keys): string
    {
        $payload = is_array($item->payload) ? $item->payload : [];
        foreach ($keys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** The site's top service/hub pages as grid cards — REAL internal links only (never invented). */
    private function serviceCards(Content $content, ?Site $site): array
    {
        $home = is_string($site?->domain_url) ? rtrim((string) $site->domain_url, '/').'/' : '/';

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('page_type', ['service', 'hub'])
            ->whereKeyNot($content->id)
            ->whereNotNull('slug')
            ->orderBy('created_at')
            ->limit(6)
            ->get();

        $cards = [];
        foreach ($pages as $page) {
            $title = trim((string) $page->title);
            if ($title === '') {
                continue;
            }
            $cards[] = [
                'title' => $title,
                // Never null — child-page description if it exists, else a generated keyword-grounded
                // blurb (see {@see ServiceCardBlurb}), so no card ships as a bare "Learn more".
                'blurb' => $this->cardBlurb->for($page),
                'url' => $home.ltrim((string) $page->slug, '/'),
            ];
        }

        return $cards;
    }

    /**
     * Hero trust stats from SUBSTANTIATED proof only — never fabricated. Each substantiated proof
     * item's short label becomes a stat; capped so the row stays a row.
     *
     * @return list<array{value: string, label: string}>
     */
    private function trustStats(Content $content): array
    {
        $items = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('is_substantiated', true)
            ->orderBy('created_at')
            ->limit(3)
            ->get();

        $stats = [];
        foreach ($items as $item) {
            $label = is_array($item->payload) ? trim((string) ($item->payload['label'] ?? '')) : '';
            if ($label !== '') {
                $stats[] = ['value' => $label, 'label' => ''];
            }
        }

        return $stats;
    }

    private function site(Content $content): ?Site
    {
        return Site::withoutGlobalScope(SiteScope::class)->find($content->site_id);
    }
}
