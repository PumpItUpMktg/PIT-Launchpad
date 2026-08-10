<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Enums\SiteStatus;
use App\Models\Site;

/**
 * NOINDEX-001 (Class D) — a tenant that isn't Live is fully crawlable. The engine always emits
 * `robots: index, follow` (MetaBlobAssembler) with no status-driven noindex, so a staging/pre-launch
 * site competes with (and can outrank a duplicate of) the client's real site. Flags every non-Live
 * tenant until a status-driven noindex exists.
 */
final class IndexableStagingCheck implements AuditCheck
{
    public function id(): string
    {
        return 'NOINDEX-001';
    }

    public function defectClass(): string
    {
        return 'D';
    }

    public function severity(): string
    {
        return Severity::Critical;
    }

    public function title(): string
    {
        return 'Non-Live tenant is indexable (no status-driven noindex)';
    }

    public function run(Site $site): array
    {
        if ($site->status === SiteStatus::Live) {
            return [];
        }

        return [new Finding(
            $site->id,
            (string) $site->brand_name,
            null,
            'status='.$site->status->value.": pages emit 'index, follow' with no status-driven noindex — staging is crawlable",
        )];
    }
}
