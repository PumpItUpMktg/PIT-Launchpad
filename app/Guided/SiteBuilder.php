<?php

namespace App\Guided;

use App\Models\Site;

/**
 * The "Approve & build" trigger — the single wiring point where an approved site's pages begin
 * generating. **Stub:** the generation composition (the fold→section assembly) and the drip
 * controller aren't landed yet, so this is intentionally a clean no-op seam. When generation
 * lands, this dispatches per-confirmed-page generation (GeneratePage/GeneratePost); the guided
 * flow already calls it, so nothing downstream changes here.
 */
class SiteBuilder
{
    public function build(Site $site): void
    {
        // Intentionally a no-op until the generation entrypoint lands (relay: wire-or-stub).
    }
}
