<?php

namespace App\SiloCreator;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\WireframeKit;
use Illuminate\Support\Str;

/**
 * Assigns each committed silo a pillar: a service_pillar silo links the service
 * page; a topical silo gets an evergreen advisory-guide pillar Content stub.
 * Here we create/link a Content stub and pin it via Silo.pillar_content_id.
 */
class PillarFactory
{
    public function ensurePillar(Silo $silo, SiloProposal $proposal): Content
    {
        $title = $proposal->isServicePillar() ? $proposal->name : 'Guide: '.$proposal->name;
        $kit = self::resolveKit($proposal->pillarPageType, $silo->site_id);

        $content = Content::create([
            'site_id' => $silo->site_id,
            'silo_id' => $silo->id,
            'kind' => ContentKind::Page,
            'page_type' => $proposal->pillarPageType,
            'status' => ContentStatus::Candidate,
            'title' => $title,
            'slug' => $this->uniqueSlug($silo->site_id, $proposal->name),
            'version' => 1,
            // Pin the content contract at birth so the pillar is generatable: the
            // drafter resolves slots from the kit and HARD-FAILS without one
            // (PageGroundingAssembler::kit throws). The kit is fully determined by
            // page_type — exactly one library kit per type — so there is nothing for
            // an operator to choose. Null only when no kit exists for the page_type
            // (e.g. a topical pillar before a hub kit ships); generation surfaces it.
            'wireframe_kit_id' => $kit?->id,
            'wireframe_kit_version' => $kit?->version,
        ]);

        $silo->update(['pillar_content_id' => $content->id]);

        return $content;
    }

    /**
     * The library kit for a page_type, preferring a per-site override over the
     * shared library kit, newest version first. WireframeKit opts out of the site
     * global scope, so this is an explicit, deterministic lookup. Public + static so
     * the repair command re-pins exactly the kit a fresh pillar would get.
     */
    public static function resolveKit(PageType $pageType, string $siteId): ?WireframeKit
    {
        return WireframeKit::query()
            ->where('page_type', $pageType->value)
            ->where('site_id', $siteId)
            ->orderByDesc('version')
            ->first()
            ?? WireframeKit::query()
                ->where('page_type', $pageType->value)
                ->whereNull('site_id')
                ->orderByDesc('version')
                ->first();
    }

    private function uniqueSlug(string $siteId, string $name): string
    {
        $base = Str::slug($name) ?: 'pillar';
        $slug = $base;
        $suffix = 1;

        while (Content::withoutGlobalScope(SiteScope::class)
            ->withTrashed()
            ->where('site_id', $siteId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
