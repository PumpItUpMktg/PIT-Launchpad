<?php

namespace App\Guided;

use App\Models\Site;

/**
 * Legacy post-finalize build trigger — a clean no-op seam, now superseded by Finalize Plan
 * materializing the manifest into planned pages that generate on demand (PageMaterializer +
 * the Pages list). Currently unreferenced; kept as the seam if a bulk-build entrypoint returns.
 */
class SiteBuilder
{
    public function build(Site $site): void
    {
        // Intentionally a no-op until the generation entrypoint lands (relay: wire-or-stub).
    }
}
