<?php

namespace App\Publishing;

use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
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
            'slot_payload' => $content->slot_payload ?? [],
            'images' => $images,
            'seo' => $this->seo($content, $images),
        ];
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
