<?php

namespace App\Audit;

/**
 * One hit from an {@see AuditCheck} — a single tenant×page (or tenant-level) instance of a defect. The
 * check owns the id/class/severity; a Finding is just where it landed and what was wrong.
 */
final class Finding
{
    public function __construct(
        public readonly string $siteId,
        public readonly string $siteName,
        /** A page slug/URL, or null for a tenant-level finding (config/status). */
        public readonly ?string $page,
        public readonly string $detail,
    ) {}
}
