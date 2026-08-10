<?php

namespace App\Audit\Checks;

use App\Audit\AuditCheck;
use App\Audit\Finding;
use App\Audit\Severity;
use App\Models\Site;

/**
 * IMG-001 (Class E) — images serve from Cloudflare's `*.r2.dev` development bucket domain (the R2 disk
 * `url` / R2_PUBLIC_URL), which is rate-limited, not for production traffic, and hands all image-search
 * equity to a domain the client doesn't own. Config-global, so it flags every tenant while the dev
 * domain is configured — the rollup then shows the whole portfolio is affected.
 */
final class R2DevImageCheck implements AuditCheck
{
    public function id(): string
    {
        return 'IMG-001';
    }

    public function defectClass(): string
    {
        return 'E';
    }

    public function severity(): string
    {
        return Severity::High;
    }

    public function title(): string
    {
        return 'Images served from the Cloudflare r2.dev dev domain';
    }

    public function run(Site $site): array
    {
        $url = (string) config('filesystems.disks.r2.url', '');
        if ($url === '' || ! str_contains($url, 'r2.dev')) {
            return [];
        }

        return [new Finding($site->id, (string) $site->brand_name, null, 'image host is the r2.dev dev domain: '.$url)];
    }
}
