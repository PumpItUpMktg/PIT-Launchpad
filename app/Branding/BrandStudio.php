<?php

namespace App\Branding;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\BrandKitAssembler;

/**
 * The testable orchestration behind the operator "Generate Brand" action (C5
 * capture). The Filament surface is thin over this: collect the short interview,
 * generate (industry auto-derived from the Service Catalog), let the operator
 * review/adjust, then save → SiteBranding → push to the Elementor Global Kit
 * (reusing the proven #105 path, so it paints the brand the cascade was validated
 * on). Works for new and existing tenants — it never depends on an active wizard.
 */
class BrandStudio
{
    public function __construct(
        private readonly BrandGenerator $generator,
        private readonly IndustryResolver $industry,
        private readonly BrandKitAssembler $assembler,
        private readonly WordpressClientFactory $factory,
    ) {}

    /** The trade descriptor pre-filled into the interview's industry field. */
    public function industryFor(Site $site): string
    {
        return $this->industry->for($site);
    }

    /**
     * Generate a brand from the interview answers. Industry uses the operator's
     * (possibly refined) value, else the derived trade.
     *
     * @param  array<string, mixed>  $answers
     */
    public function generate(Site $site, array $answers): GeneratedBrand
    {
        $industry = trim((string) ($answers['industry'] ?? ''));
        if ($industry === '') {
            $industry = $this->industry->for($site);
        }

        return $this->generateFromAnswers(['industry' => $industry] + $answers);
    }

    /**
     * Generate from interview answers alone (industry already resolved/pre-filled).
     * The inline review action calls this — the industry field is pre-filled at
     * modal open, so no Site lookup is needed mid-form.
     *
     * @param  array<string, mixed>  $answers
     */
    public function generateFromAnswers(array $answers): GeneratedBrand
    {
        $industry = trim((string) ($answers['industry'] ?? ''));

        $brief = new BrandBrief(
            industry: $industry !== '' ? $industry : 'local service',
            personality: (string) ($answers['personality'] ?? 'trustworthy'),
            emotionalGoal: trim((string) ($answers['emotional_goal'] ?? '')),
            colorAnchorsUse: $this->splitList($answers['color_anchors_use'] ?? ''),
            colorAnchorsAvoid: $this->splitList($answers['color_anchors_avoid'] ?? ''),
            admiredBrand: trim((string) ($answers['admired_brand'] ?? '')),
        );

        return $this->generator->generate($brief);
    }

    /**
     * Persist the reviewed palette + typography onto the Site's branding. Operator
     * edits in the review screen flow through here; other branding fields (logo,
     * NAP, …) are preserved. Operator-context safe (explicit site_id, no scope).
     *
     * @param  array{primary?: string, accent?: string, text?: string}  $palette
     * @param  array{heading?: string, body?: string}  $typography
     */
    public function save(Site $site, array $palette, array $typography): SiteBranding
    {
        return SiteBranding::withoutGlobalScope(SiteScope::class)->updateOrCreate(
            ['site_id' => $site->id],
            ['palette' => $palette, 'typography' => $typography],
        );
    }

    /**
     * Push the saved brand into the site's Elementor Global Kit (the #105 path).
     * Returns the plugin's structured result; a transport failure is captured as a
     * non-fatal result so the surface can report it without throwing.
     *
     * @return array<string, mixed>
     */
    public function push(Site $site): array
    {
        $payload = $this->assembler->forSite((string) $site->id);
        if ($payload === null) {
            return ['updated' => false, 'error' => 'No brand captured to push.'];
        }

        try {
            return $this->factory->forSite($site)->upsertBrandKit($payload);
        } catch (WordpressException $e) {
            return ['updated' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return list<string>
     */
    private function splitList(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        return collect(explode(',', (string) $value))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }
}
