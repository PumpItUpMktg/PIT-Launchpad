<?php

namespace App\ContentEngine\Reconcile;

/**
 * One line of the per-tenant readiness/staleness checklist (§B slice 5) — a build stage, its
 * traffic-light state, a plain-language detail, and the fix (which reconciler re-aligns it, if any).
 * A pure view of persisted rows: computed by {@see RebuildReadiness}, rendered by the Operate surface.
 */
final class ReadinessRow
{
    /**
     * @param  string  $key  stable machine key (e.g. 'blog_routing')
     * @param  string  $step  the display marker (e.g. '⑦')
     * @param  ReadinessStatus  $status  traffic-light state
     * @param  string  $label  the stage name
     * @param  string  $detail  the plain-language finding
     * @param  string|null  $fix  the fix hint (e.g. 're-route'), null when nothing to do
     */
    public function __construct(
        public readonly string $key,
        public readonly string $step,
        public readonly ReadinessStatus $status,
        public readonly string $label,
        public readonly string $detail,
        public readonly ?string $fix = null,
    ) {}

    /** @return array{key: string, step: string, status: string, glyph: string, label: string, detail: string, fix: string|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'step' => $this->step,
            'status' => $this->status->value,
            'glyph' => $this->status->glyph(),
            'label' => $this->label,
            'detail' => $this->detail,
            'fix' => $this->fix,
        ];
    }
}
