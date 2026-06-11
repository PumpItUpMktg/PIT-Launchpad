<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteTemplateMapping;
use App\PageBuilder\Schema\KitSchema;
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
            $slots['body'] = $content->body;
        }

        return $slots;
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
            'title' => (string) ($metaSeo['title'] ?? $content->title),
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
