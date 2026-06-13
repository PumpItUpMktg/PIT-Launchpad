<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\ConversionConfig;
use App\Models\Location;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteTemplateMapping;
use App\PageBuilder\Schema\KitSchema;
use App\PageBuilder\Validation\PublishEligibility;
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
    public function __construct(private readonly PublishEligibility $eligibility) {}

    /**
     * @param  Collection<int, RenderJob>  $renderJobs
     * @return array<string, mixed>
     */
    public function assemble(Content $content, Collection $renderJobs): array
    {
        $images = $this->images($renderJobs);

        return [
            'content_id' => $content->id,
            'kind' => $content->kind->value,
            'page_type' => $content->page_type?->value,
            'kit' => $this->kitName($content),
            'kit_version' => (string) ($content->wireframe_kit_version ?? ''),
            'silo_id' => $content->silo_id,
            'slug' => $content->slug,
            'status' => 'published',
            'locked' => (bool) $content->locked,
            'slot_payload' => $this->slotPayload($content),
            'kit_definition' => $this->kitDefinition($content),
            'template_id' => $this->templateId($content),
            'images' => $images,
            'featured_image' => $this->ogImageUrl($content, $images),
            'seo' => $this->seo($content, $images),
        ];
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

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function resolveCtaSlot(Content $content, ?Location $location, array $slots): array
    {
        $phone = $location?->phone;

        if ($phone === null || trim($phone) === '') {
            unset($slots['cta']); // no derivable phone → no conversion block

            return $slots;
        }

        $formEmbed = ConversionConfig::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->value('ghl_form_embed');

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

        return array_filter([
            'title' => SeoTitle::normalize((string) ($metaSeo['title'] ?? $content->title), $content->source_name),
            'meta_description' => $metaSeo['meta_description'] ?? null,
            'canonical' => $this->canonical($content),
            'robots' => 'index, follow',
            'og' => $ogImage !== null ? ['image' => $ogImage] : [],
            'schema_type' => $content->schema_type,
            'schema_payload' => $content->schema_payload,
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
     * @return list<array{name: string, url: string}>
     */
    private function breadcrumbs(Content $content): array
    {
        $site = $this->site($content);
        $home = is_string($site?->domain_url) ? rtrim($site->domain_url, '/').'/' : '/';

        $crumbs = [['name' => 'Home', 'url' => $home]];

        if ($content->silo_id !== null && $content->silo !== null) {
            $crumbs[] = ['name' => (string) $content->silo->name, 'url' => ''];
        }

        $crumbs[] = ['name' => (string) $content->title, 'url' => ''];

        return $crumbs;
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
