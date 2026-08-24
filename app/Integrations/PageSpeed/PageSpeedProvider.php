<?php

namespace App\Integrations\PageSpeed;

/**
 * The Core Web Vitals seam — measures one URL's page-speed / CWV, vendor-agnostic. The default binding
 * ({@see PageSpeedInsights}) calls Google's free PageSpeed Insights API; tests bind a fake. `enabled()`
 * gates the whole feature so a deployment without the integration turned on shows the honest "not measured
 * yet" state rather than fabricated scores.
 */
interface PageSpeedProvider
{
    public function enabled(): bool;

    /** Measure one URL, or null on an API error / when the tool returned no usable performance data. */
    public function measure(string $url, string $strategy = 'mobile'): ?PageVitals;
}
