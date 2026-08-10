<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * SLOT-001 (Class A) — a LIVE service (spoke) page whose `primary_service_id` is null. The composer
 * resolves warning-signs/scope/process/cost live from a pinned Service; a null pin silently falls back
 * to the silo's alphabetically-first sibling, so the page renders a different service's structured data
 * (the Emergency-Plumbing-shows-Drain-Cleaning bleed). Detected in the control plane — no eyeballing.
 */
final class SpokeServicePinCheck implements AuditCheck
{
    public function id(): string
    {
        return 'SLOT-001';
    }

    public function defectClass(): string
    {
        return 'A';
    }

    public function severity(): string
    {
        return Severity::Critical;
    }

    public function title(): string
    {
        return 'Live service page has no pinned service (structured slots bleed from a sibling)';
    }

    public function run(Site $site): array
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Service->value)
            ->whereNotNull('wp_post_id')
            ->whereNull('primary_service_id')
            ->orderBy('slug')
            ->get()
            ->map(fn (Content $c): Finding => new Finding(
                $site->id,
                (string) $site->brand_name,
                (string) $c->slug,
                'primary_service_id is null — warning-signs/scope/process/cost render the silo\'s first sibling service',
            ))
            ->values()
            ->all();
    }
}
