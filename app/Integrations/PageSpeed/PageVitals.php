<?php

namespace App\Integrations\PageSpeed;

/**
 * A normalized Core Web Vitals reading for one URL — the vendor-agnostic contract the PageSpeed seam
 * emits. Metrics are the lab (Lighthouse) values: an overall performance score plus the three CWV
 * (LCP, CLS, INP). Any metric may be null when the tool didn't report it.
 */
final class PageVitals
{
    public function __construct(
        public readonly string $url,
        public readonly string $strategy,       // mobile | desktop
        public readonly int $performanceScore,  // 0–100
        public readonly ?int $lcpMs,            // Largest Contentful Paint, ms
        public readonly ?float $cls,            // Cumulative Layout Shift, unitless
        public readonly ?int $inpMs,            // Interaction to Next Paint, ms (null when not reported)
    ) {}

    /**
     * "Good" on the Core Web Vitals thresholds Google publishes: LCP ≤ 2.5s, CLS ≤ 0.1, INP ≤ 200ms.
     * INP only counts when it was measured (older Lighthouse runs omit it). LCP and CLS must both be
     * present and good — a reading missing them can't be called a pass.
     */
    public function passesCoreWebVitals(): bool
    {
        if ($this->lcpMs === null || $this->cls === null) {
            return false;
        }

        return $this->lcpMs <= 2500 && $this->cls <= 0.1 && ($this->inpMs === null || $this->inpMs <= 200);
    }
}
