<?php

namespace App\SiloCreator;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
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

        $content = Content::create([
            'site_id' => $silo->site_id,
            'silo_id' => $silo->id,
            'kind' => ContentKind::Page,
            'page_type' => $proposal->pillarPageType,
            'status' => ContentStatus::Candidate,
            'title' => $title,
            'slug' => $this->uniqueSlug($silo->site_id, $proposal->name),
            'version' => 1,
        ]);

        $silo->update(['pillar_content_id' => $content->id]);

        return $content;
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
