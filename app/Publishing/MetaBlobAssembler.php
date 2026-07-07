<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentSource;
use App\Enums\SlotContentType;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\Location;
use App\Models\PageConfig;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteTemplateMapping;
use App\PageBuilder\Library\FormHeroComposer;
use App\PageBuilder\Library\LibraryHubComposer;
use App\PageBuilder\Library\LibraryServiceComposer;
use App\PageBuilder\Native\NativeComposer;
use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Validation\PublishEligibility;
use App\Publishing\Blocks\BlockContentAssembler;
use App\Publishing\Schema\ServiceSchemaBuilder;
use App\Support\SeoTitle;
use Illuminate\Support\Collection;

/**
 * Assembles a Content (its §3a kit slots, rendered images, and engine-owned SEO)
 * into the single consolidated meta-blob the companion plugin's /content
 * endpoint upserts — NO ACF. Slot values pass through keyed by slot key (the
 * plugin's dynamic tags read them by key); the kit's seo_binding picks the OG
 * image; SEO is the engine's. Shape matches the companion-plugin contract.
 */
class MetaBlobAssembler
{
    /** A neutral gray SVG box (data URI) the placeholder preview uses for image slots. */
    private const PLACEHOLDER_IMAGE = "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='1200'%20height='800'%3E%3Crect%20width='100%25'%20height='100%25'%20fill='%23e2e8f0'/%3E%3Ctext%20x='50%25'%20y='50%25'%20fill='%23475569'%20font-size='52'%20font-family='sans-serif'%20text-anchor='middle'%20dominant-baseline='middle'%3EImage%3C/text%3E%3C/svg%3E";

    /** @var array<string, PageConfig|null> memoized per content id */
    private array $pageConfigs = [];

    public function __construct(
        private readonly PublishEligibility $eligibility,
        private readonly NativeComposer $composer,
        private readonly LibraryServiceComposer $libraryService,
        private readonly LibraryHubComposer $libraryHub,
        private readonly ServiceSchemaBuilder $serviceSchema,
        private readonly BlockContentAssembler $blockContent,
    ) {}

    /**
     * The page's user-owned config (operator overrides). Read by content_id on every
     * compose so a repush re-injects it verbatim. Memoized per content.
     */
    private function pageConfig(Content $content): ?PageConfig
    {
        if (! array_key_exists($content->id, $this->pageConfigs)) {
            $this->pageConfigs[$content->id] = PageConfig::query()->where('content_id', $content->id)->first();
        }

        return $this->pageConfigs[$content->id];
    }

    /**
     * Length-representative placeholder slots for the page's kit (or a body stand-in
     * for a post) — fed to the SAME composer so the preview is the real skeleton.
     *
     * @return array<string, mixed>
     */
    private function placeholderSlots(Content $content): array
    {
        $schema = $this->schema($content);
        if ($schema !== null) {
            return (new PlaceholderSlots)->forSchema($schema);
        }

        return ['body' => '<p>This is length-representative body copy that runs a realistic paragraph so the layout reads as it will with the real article in place.</p>'];
    }

    /**
     * Placeholder image map — a stand-in box for each image slot the kit renders.
     *
     * @return array<string, array<string, mixed>>
     */
    private function placeholderImages(Content $content): array
    {
        $schema = $this->schema($content);
        $images = [];
        if ($schema !== null) {
            foreach ($schema->slots as $slot) {
                if (in_array($slot->contentType, [SlotContentType::Image, SlotContentType::Gallery], true)) {
                    $images[$slot->key] = ['url' => self::PLACEHOLDER_IMAGE, 'alt' => 'Placeholder image'];
                }
            }
        }

        if ($images === []) {
            $images['hero_image'] = ['url' => self::PLACEHOLDER_IMAGE, 'alt' => 'Placeholder image'];
        }

        return $images;
    }

    /**
     * Apply the per-page hero image override (user-owned) onto the rendered image
     * map — the operator-set URL wins over the generated hero. Alt text is preserved.
     *
     * @param  array<string, array<string, mixed>>  $images
     * @return array<string, array<string, mixed>>
     */
    private function heroImageOverride(Content $content, array $images): array
    {
        $override = $this->pageConfig($content)?->hero_image_override;
        if (is_string($override) && trim($override) !== '') {
            $images['hero_image'] = [
                'url' => trim($override),
                'alt' => (string) ($images['hero_image']['alt'] ?? ''),
            ];
        }

        return $images;
    }

    /**
     * @param  Collection<int, RenderJob>  $renderJobs
     * @param  bool  $preview  operator proof-view context — the composed page builds ALL recommended
     *                         sections with labeled example placeholders for missing data; publish
     *                         (default false) keeps the data-gating so placeholders never go live.
     * @return array<string, mixed>
     */
    public function assemble(Content $content, Collection $renderJobs, ContentSource $source = ContentSource::Generated, string $wpStatus = 'published', bool $preview = false): array
    {
        // Placeholder = the SAME composed page with length-representative stand-ins +
        // image/form boxes (preview = reality; only slot content differs).
        $placeholder = $source === ContentSource::Placeholder;
        $images = $placeholder
            ? $this->placeholderImages($content)
            : $this->heroImageOverride($content, $this->images($renderJobs));
        $slots = $placeholder ? $this->placeholderSlots($content) : $this->slotPayload($content);

        return [
            'content_id' => $content->id,
            'kind' => $content->kind->value,
            'page_type' => $content->page_type?->value,
            'kit' => $this->kitName($content),
            'kit_version' => (string) ($content->wireframe_kit_version ?? ''),
            'silo_id' => $content->silo_id,
            'slug' => $content->slug,
            // 'published' → live; anything else → a WP draft (the plugin's mapping). The proof-step
            // preview-push sends 'draft' so the page renders in WP without going live.
            'status' => $wpStatus,
            'locked' => (bool) $content->locked,
            // The RESOLVED slots stay the source of truth the plugin keys SEO/schema
            // off (FAQPage reads slot_payload.faq) — retained ALONGSIDE the native
            // body below, never replaced by it.
            'slot_payload' => $slots,
            'kit_definition' => $this->kitDefinition($content),
            'template_id' => $this->templateId($content),
            'images' => $images,
            'featured_image' => $this->ogImageUrl($content, $images),
            'seo' => $this->seo($content, $images),
            // Tier-1 native body: the page renders as native, editable Elementor
            // widgets baked from the SAME resolved slots. Pages only; posts ([] →
            // plugin no-op) keep the single-post template render.
            'elementor_data' => $this->nativeBody($content, $slots, $images, $source),
            // Gutenberg pivot: core-block markup for the WP post_content. Non-null only for page
            // types whose block pattern has shipped (home first); the Layer-5 plugin prefers this
            // over elementor_data when present. Composed from the SAME resolved slots + images.
            'post_content' => $this->blockContent->compose($content, $slots, $images, $preview),
        ];
    }

    /**
     * The per-page NATIVE Elementor body (the Tier-1 widget document) baked from the
     * resolved slots — composed from the SAME values as slot_payload, so the native
     * render and the SEO/schema source never diverge. Pages only; posts and
     * schema-less content return [] (the plugin then writes no native body).
     *
     * @param  array<string, mixed>  $slots
     * @param  array<string, array<string, mixed>>  $images
     * @return list<array<string, mixed>>
     */
    private function nativeBody(Content $content, array $slots, array $images, ContentSource $source = ContentSource::Generated): array
    {
        if ($content->kind !== ContentKind::Page) {
            return [];
        }

        // Service pages render off the verified wireframe library (Tier-1 migration).
        // NativeComposer stays the fallback for not-yet-migrated page types — it is
        // NOT retired until the published service page confirms the publish path.
        if ($content->page_type?->value === 'service') {
            $tree = $this->libraryService->compose($slots, $images);

            // Form-hero variant (user-owned): swap the standard hero for the media
            // hero + form card. The form embed = the page config's (or the placeholder
            // box); the phone comes from the resolved cta slot.
            $config = $this->pageConfig($content);
            if ($config?->usesFormHero()) {
                $cta = is_array($slots['cta'] ?? null) ? $slots['cta'] : [];
                $formEmbed = $source === ContentSource::Placeholder
                    ? PlaceholderSlots::FORM_BOX
                    : ($config->form_embed ?: PlaceholderSlots::FORM_BOX);
                $tree = (new FormHeroComposer)->swapHero(
                    $tree, $slots, $images, $formEmbed,
                    is_string($cta['phone'] ?? null) ? $cta['phone'] : null,
                    is_string($cta['tel'] ?? null) ? $cta['tel'] : null,
                );
            }

            return $tree;
        }

        // Silo-pillar HUB pages render off the same verified library (service-hub assembly).
        if ($content->page_type?->value === 'hub') {
            return $this->libraryHub->compose($slots, $images);
        }

        $schema = $this->schema($content);
        if ($schema === null) {
            return [];
        }

        return $this->composer->compose($schema, $slots, $images, $content->id);
    }

    /**
     * The operator's resolved Elementor template for this page's kit (§7b
     * mapping), or null when unmapped / not a page. The companion plugin stamps
     * the page's kit marker (`lp_kit` term) and surfaces this id as the template
     * whose Theme Builder display condition should target that marker — explicit
     * mapping over the kit's elementor_template_ref suggestion. Posts carry no kit
     * template, so they resolve to null.
     */
    private function templateId(Content $content): ?int
    {
        if ($content->kind !== ContentKind::Page) {
            return null;
        }

        $mapping = SiteTemplateMapping::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('kit', $this->kitName($content))
            ->first();

        return $mapping?->template_id;
    }

    /**
     * The slot blob pushed to WordPress. A POST's article body is not a kit slot
     * (it lives in the `body` column), so it would never reach WP and the post
     * would render blank. Carry it as the `body` slot — the plugin then mirrors it
     * to the readable `lp_slot_body` meta and renders it via [lp_slot key="body"]
     * (the single-post template's content section). Pages pass their kit slots
     * through unchanged.
     *
     * @return array<string, mixed>
     */
    private function slotPayload(Content $content): array
    {
        $slots = is_array($content->slot_payload) ? $content->slot_payload : [];

        if ($content->kind === ContentKind::Post
            && is_string($content->body)
            && trim($content->body) !== ''
            && ! array_key_exists('body', $slots)) {
            $slots['body'] = $this->normalizeBody($content->body);
        }

        // Page slots get the same placeholder-token guarantee (a re-push cleans an
        // existing page's FAQ answers / copy of stray <sup>[…]</sup> or [token]
        // markers — page 196's FAQPage schema carried them). Recurses the slot
        // tree, leaving structure intact and only scrubbing string leaves.
        if ($content->kind === ContentKind::Page) {
            $slots = $this->scrubTokens($slots);

            $schema = $content->wireframe_kit_id !== null ? $content->wireframeKit?->schema() : null;
            if ($schema !== null) {
                // Honor the kit's conditions: a slot whose condition isn't met by the
                // publish-time flags is omitted (why_us with no proof, testimonial
                // with no reviews, …) — generated copy for an unmet section never ships.
                $slots = $this->dropUnmetConditionalSlots($content, $slots, $schema);
                // Resolve the derived conversion slots AFTER the scrub so the GHL
                // form-embed HTML is never token-stripped.
                $slots = $this->resolveConversionSlots($content, $slots, $schema);
            }
        }

        return $slots;
    }

    /**
     * Drop any slot whose kit condition is not satisfied by the page's publish-time
     * flags (reusing §3a's single flag source). Entity slots the model never emits
     * are already absent; this matters for generated/grounded conditional slots
     * (e.g. why_us), so an unearned section's copy doesn't render.
     *
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function dropUnmetConditionalSlots(Content $content, array $slots, KitSchema $schema): array
    {
        $flags = $this->eligibility->contextFor($content)->flags;

        foreach ($schema->slots as $slot) {
            if (! $slot->appliesTo($flags) && array_key_exists($slot->key, $slots)) {
                unset($slots[$slot->key]);
            }
        }

        return $slots;
    }

    /**
     * Fill the structural conversion slots from §1 data — these are platform-derived,
     * not model copy, so they overwrite whatever the drafter emitted:
     *
     *  - `cta` → the dual conversion block: a "Call Now" tel: link derived from the
     *    primary location's phone (the always-present floor) PLUS, when configured,
     *    the site's GoHighLevel lead-form embed. No phone → the slot is omitted
     *    (the has_location_phone floor wasn't met); no form → call-button-only.
     *  - `contact_block` → the primary location's NAP. No location → omitted.
     *
     * Applied only when the page's kit declares the slot, so it is a no-op for kits
     * that don't (e.g. a post body).
     *
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function resolveConversionSlots(Content $content, array $slots, KitSchema $schema): array
    {
        $slotKeys = array_map(fn ($slot) => $slot->key, $schema->slots);
        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->orderBy('created_at')
            ->first();

        if (in_array('cta', $slotKeys, true)) {
            $slots = $this->resolveCtaSlot($content, $location, $slots);
        }

        if (in_array('contact_block', $slotKeys, true)) {
            $slots = $this->resolveContactBlock($location, $slots);
        }

        if (in_array('sibling_services', $slotKeys, true)) {
            $slots = $this->resolveSiblingServices($content, $slots);
        }

        return $slots;
    }

    /**
     * Fill the hub page's `sibling_services` grid from the silo's child service pages —
     * the §4 structure, not drafted copy. Each card is a {title, body} where the body
     * carries a "Learn more" link to the child page (the grid card is a text hook, so
     * the link rides inside its HTML). Capped at 3 (the service-hub block is 3 cards);
     * an empty result drops the slot and the block self-prunes. The hub page itself is
     * its silo's pillar, so it's excluded.
     *
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function resolveSiblingServices(Content $content, array $slots): array
    {
        if ($content->silo_id === null) {
            unset($slots['sibling_services']);

            return $slots;
        }

        $home = is_string($this->site($content)?->domain_url) ? rtrim((string) $this->site($content)->domain_url, '/').'/' : '/';

        $siblings = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('silo_id', $content->silo_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', 'service')
            ->whereKeyNot($content->id)
            ->orderBy('created_at')
            ->limit(3)
            ->get();

        $cards = [];
        foreach ($siblings as $sibling) {
            $title = trim((string) $sibling->title);
            if ($title === '') {
                continue;
            }
            $metaSeo = is_array($sibling->meta['seo'] ?? null) ? $sibling->meta['seo'] : [];
            $blurb = trim((string) ($metaSeo['meta_description'] ?? ''));
            $url = $home.ltrim((string) $sibling->slug, '/');
            $body = ($blurb !== '' ? '<p>'.e($blurb).'</p>' : '')
                .'<p><a href="'.e($url).'">Learn more →</a></p>';

            $cards[] = ['title' => $title, 'body' => $body];
        }

        if ($cards === []) {
            unset($slots['sibling_services']);

            return $slots;
        }

        $slots['sibling_services'] = $cards;

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function resolveCtaSlot(Content $content, ?Location $location, array $slots): array
    {
        $config = $this->pageConfig($content);

        // The per-page phone override (user-owned) wins over the §1 location phone.
        $override = $config?->phone_override;
        $phone = is_string($override) && trim($override) !== '' ? $override : $location?->phone;

        if ($phone === null || trim($phone) === '') {
            unset($slots['cta']); // no derivable phone → no conversion block

            return $slots;
        }

        // The per-page form embed (user-owned) wins over the site GHL config.
        $formEmbed = $config?->form_embed;
        if (! is_string($formEmbed) || trim($formEmbed) === '') {
            $formEmbed = ConversionConfig::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $content->site_id)
                ->value('ghl_form_embed');
        }

        $slots['cta'] = array_filter([
            'type' => 'conversion_block',
            'call_label' => 'Call Now',
            'phone' => $phone,
            'tel' => 'tel:'.preg_replace('/[^0-9+]/', '', $phone),
            'form_embed' => is_string($formEmbed) && trim($formEmbed) !== '' ? $formEmbed : null,
        ], static fn ($v) => $v !== null);

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function resolveContactBlock(?Location $location, array $slots): array
    {
        if ($location === null) {
            unset($slots['contact_block']);

            return $slots;
        }

        $slots['contact_block'] = array_filter([
            'type' => 'nap',
            'name' => $location->name,
            'address' => $location->address,
            'phone' => $location->phone,
            'hours' => $location->hours,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $slots;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function scrubTokens(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $value[$key] = $this->stripPlaceholderTokens($item);
            } elseif (is_array($item)) {
                $value[$key] = $this->scrubTokens($item);
            }
        }

        return $value;
    }

    /**
     * Normalize the article body at publish — the GUARANTEE that re-pushing cleans
     * existing posts (no re-draft). Idempotent: a clean body is returned unchanged.
     */
    private function normalizeBody(string $body): string
    {
        return $this->stripPlaceholderTokens($this->stripLeadingH1($body));
    }

    /**
     * Drop the article's own opening <h1>: the native Post Title widget renders the
     * title, so a body <h1> duplicates it. The <h1> is removed wherever it is the
     * FIRST content — including inside a wrapper (`<article>`, `<section>`, …),
     * which the old position-0 match missed (post 181 opened `<article><h1>…`).
     * Leading wrappers/whitespace are preserved; an <h1> after real content stays.
     */
    private function stripLeadingH1(string $body): string
    {
        return (string) preg_replace(
            '/^(\s*(?:<(?:article|section|div|header|main|body)\b[^>]*>\s*)*)<h1\b[^>]*>.*?<\/h1>\s*/is',
            '$1',
            $body,
            1,
        );
    }

    /**
     * Strip placeholder / citation / annotation markers the drafter must never emit
     * — `<sup>[review]</sup>` / `<sup>[warranty]</sup>` annotation tokens (post 174)
     * and bare single-word bracket tokens like `[warranty]`. Substantiated proof is
     * written as natural prose, never spliced as a marker; the prompt enforces it,
     * this guarantees it.
     */
    private function stripPlaceholderTokens(string $body): string
    {
        $body = (string) preg_replace('/<sup>\s*\[[^\]]*\]\s*<\/sup>/i', '', $body);

        return (string) preg_replace('/\[[a-z][a-z0-9_-]*\]/', '', $body);
    }

    /**
     * A trimmed, contract-level definition of the kit's slots (key / label /
     * content_type / cardinality / required) so the companion plugin's reference
     * screen reflects the CONTRACT, not just observed slot data. Empty when the
     * content has no resolvable kit.
     *
     * @return list<array<string, mixed>>
     */
    private function kitDefinition(Content $content): array
    {
        $schema = $this->schema($content);
        if ($schema === null) {
            return [];
        }

        $defs = [];
        foreach ($schema->slots as $slot) {
            $defs[] = [
                'key' => $slot->key,
                'label' => $slot->label,
                'content_type' => $slot->contentType->value,
                'cardinality' => [
                    'type' => $slot->cardinality->type,
                    'min' => $slot->cardinality->min,
                    'max' => $slot->cardinality->max,
                ],
                'required' => $slot->isRequired(),
            ];
        }

        return $defs;
    }

    /**
     * @param  Collection<int, RenderJob>  $renderJobs
     * @return array<string, array<string, mixed>>
     */
    private function images(Collection $renderJobs): array
    {
        $images = [];
        foreach ($renderJobs as $job) {
            $object = $job->toImageObject();
            if ($object !== null && $job->slot !== null) {
                $images[$job->slot] = $object;
            }
        }

        return $images;
    }

    /**
     * @param  array<string, array<string, mixed>>  $images
     * @return array<string, mixed>
     */
    private function seo(Content $content, array $images): array
    {
        $metaSeo = is_array($content->meta['seo'] ?? null) ? $content->meta['seo'] : [];
        $ogImage = $this->ogImageUrl($content, $images);

        [$schemaType, $schemaPayload] = $this->seoSchema($content);

        return array_filter([
            'title' => $this->seoTitle($content),
            'meta_description' => $metaSeo['meta_description'] ?? null,
            'canonical' => $this->canonical($content),
            'robots' => 'index, follow',
            'og' => $ogImage !== null ? ['image' => $ogImage] : [],
            'schema_type' => $schemaType,
            'schema_payload' => $schemaPayload,
            'breadcrumbs' => $this->breadcrumbs($content),
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /**
     * The OG image is the rendered image for the slot the kit binds to og_image
     * (typically the hero), falling back to the first rendered image.
     *
     * @param  array<string, array<string, mixed>>  $images
     */
    private function ogImageUrl(Content $content, array $images): ?string
    {
        if ($images === []) {
            return null;
        }

        $schema = $this->schema($content);
        if ($schema !== null) {
            foreach ($schema->slots as $slot) {
                if ($slot->seoBinding === 'og_image' && isset($images[$slot->key]['url'])) {
                    return (string) $images[$slot->key]['url'];
                }
            }
        }

        $first = reset($images);

        return isset($first['url']) ? (string) $first['url'] : null;
    }

    private function canonical(Content $content): ?string
    {
        $site = $this->site($content);
        $domain = $site?->domain_url;

        if (! is_string($domain) || $domain === '') {
            return null;
        }

        return rtrim($domain, '/').'/'.ltrim($content->slug, '/');
    }

    /**
     * The page-type schema [type, payload] pushed as seo.schema_type/schema_payload.
     * A service PAGE gets the live-composed Service node (ServiceSchemaBuilder, from
     * §1 at assemble time); every other content keeps its stored Content columns.
     *
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    private function seoSchema(Content $content): array
    {
        if ($content->kind === ContentKind::Page && $content->page_type?->value === 'service') {
            $site = $this->site($content);
            if ($site !== null) {
                $home = is_string($site->domain_url) ? rtrim($site->domain_url, '/').'/' : '/';

                return ['Service', $this->serviceSchema->build($content, $site, $home, $this->canonical($content))];
            }
        }

        $payload = is_array($content->schema_payload) ? $content->schema_payload : null;

        return [$content->schema_type, $payload];
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function breadcrumbs(Content $content): array
    {
        $site = $this->site($content);
        $home = is_string($site?->domain_url) ? rtrim($site->domain_url, '/').'/' : '/';

        $crumbs = [['name' => 'Home', 'url' => $home]];

        // Skip the silo crumb when this page IS its silo's pillar — the crumb would
        // link to (and be named the same as) this very page. Collapse to Home → Leaf.
        if ($content->silo_id !== null && $content->silo !== null && ! $this->isOwnSiloPillar($content)) {
            $crumbs[] = ['name' => (string) $content->silo->name, 'url' => $this->siloUrl($content, $home)];
        }

        // Leaf = the CURRENT page title (the normalized SEO title), not the stale
        // internal Content.title.
        $crumbs[] = ['name' => $this->seoTitle($content), 'url' => ''];

        return $crumbs;
    }

    /**
     * Is this page its own silo's pillar? Then the silo crumb resolves to this very
     * page (same name, same URL) — a self-referential crumb. pillarContent is a
     * belongsTo on pillar_content_id, so it equals the current page only when the
     * silo's pillar IS this page (never a child page).
     */
    private function isOwnSiloPillar(Content $content): bool
    {
        return $content->silo?->pillarContent?->id === $content->id;
    }

    /**
     * The silo crumb's link — its pillar page (the silo landing page). Empty when the
     * silo has no pillar yet (the crumb then renders unlinked rather than broken).
     */
    private function siloUrl(Content $content, string $home): string
    {
        $pillar = $content->silo?->pillarContent;
        $slug = $pillar !== null ? trim((string) $pillar->slug, '/') : '';

        return $slug !== '' ? $home.$slug.'/' : '';
    }

    /**
     * The page's normalized SEO/document title — the single source for the <title>,
     * the OG title, and the breadcrumb leaf, so they never drift.
     */
    private function seoTitle(Content $content): string
    {
        $metaSeo = is_array($content->meta['seo'] ?? null) ? $content->meta['seo'] : [];

        return SeoTitle::normalize((string) ($metaSeo['title'] ?? $content->title), $content->source_name);
    }

    private function kitName(Content $content): string
    {
        // A post has no kit — never synthesize a 'page-page' kit for it (that
        // leaked into the plugin's lp-kit-* body class and a bogus kit marker).
        if ($content->kind === ContentKind::Post) {
            return '';
        }

        $kit = $content->wireframe_kit_id !== null ? $content->wireframeKit : null;

        if ($kit !== null && $kit->name !== '') {
            return (string) $kit->name;
        }

        $pageType = $content->page_type !== null ? $content->page_type->value : 'page';

        return $pageType.'-page';
    }

    private function schema(Content $content): ?KitSchema
    {
        return $content->wireframe_kit_id !== null ? $content->wireframeKit?->schema() : null;
    }

    private function site(Content $content): ?Site
    {
        return Site::withoutGlobalScope(SiteScope::class)->find($content->site_id);
    }
}
