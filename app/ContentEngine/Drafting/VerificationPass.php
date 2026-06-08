<?php

namespace App\ContentEngine\Drafting;

/**
 * The post-draft accuracy audit. Each asserted business claim must trace to a
 * real entry in the Claims pool (by id); ones that do not are flagged, never
 * dropped. Cited sources are recorded as attributions with the citation-URL
 * policy applied (non-canonical / aggregator-redirect URLs collapse to
 * name-only). Because claims and sources are separate output lists, a source
 * smuggled in as a business claim surfaces here as an unsupported assertion.
 */
class VerificationPass
{
    public function verify(DraftPayload $payload, Grounding $grounding): VerificationResult
    {
        $supported = [];
        $unsupported = [];

        foreach ($payload->assertedClaims as $asserted) {
            $entry = ['text' => $asserted->text, 'claim_id' => $asserted->claimId];

            if ($asserted->claimId !== null && $grounding->claim($asserted->claimId) !== null) {
                $supported[] = $entry;
            } else {
                $unsupported[] = $entry;
            }
        }

        $attributions = [];
        foreach ($payload->citedSources as $cited) {
            // Prefer the pool's known URL for this source; fall back to what the
            // model cited. Either way the citation policy decides whether a link
            // survives — a Google News redirect token never does.
            $pooled = $grounding->source($cited['name']);
            $url = $pooled !== null ? $pooled->url : $cited['url'];

            $attributions[] = [
                'name' => $cited['name'],
                'url' => SourceRef::urlIsCitable($url) ? $url : null,
            ];
        }

        return new VerificationResult(
            supportedClaims: $supported,
            unsupportedClaims: $unsupported,
            sourceAttributions: $attributions,
        );
    }
}
