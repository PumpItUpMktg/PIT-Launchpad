<?php

namespace App\ColdOutreach;

use App\Enums\ColdContactStage;
use App\Integrations\ColdOutreach\EmailVerdict;
use App\Models\ColdOutreachConfig;

/**
 * Turns a Bird verdict into a pipeline stage. Pure — no I/O.
 *
 * - undeliverable / typo / disposable → Invalid (never mailed; a typo's suggestion is kept for review)
 * - valid → Verified when confidence ≥ the site's floor, else Risky
 * - neutral (catch-all) → Verified only when the site accepts neutral and confidence clears the floor
 * - risky (role / low reputation) → Risky — held for an operator to approve or drop
 * - an unknown future verdict → decided on confidence alone (Bird's documented fallback)
 */
class DeliverabilityGate
{
    public function stageFor(EmailVerdict $verdict, ColdOutreachConfig $config): ColdContactStage
    {
        if (in_array($verdict->result, ['undeliverable', 'typo'], true) || in_array('disposable', $verdict->flags, true)) {
            return ColdContactStage::Invalid;
        }

        $clears = $verdict->confidence >= $config->min_confidence;

        return match ($verdict->result) {
            'valid' => $clears ? ColdContactStage::Verified : ColdContactStage::Risky,
            'neutral' => $config->accept_neutral && $clears ? ColdContactStage::Verified : ColdContactStage::Risky,
            'risky' => ColdContactStage::Risky,
            default => $clears ? ColdContactStage::Verified : ColdContactStage::Risky,
        };
    }
}
