<?php

namespace App\Standard;

use App\Enums\MediaKind;
use App\Enums\MediaSource;
use App\Enums\ProofType;
use App\Enums\StandardPageType;
use App\Models\MediaAsset;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Data-gating for the optional standard pages: a toggle is offered only when the site has the
 * data to fill the page — never offer a Gallery toggle to a client with no photos. Reads the
 * real §1 sources (ProofItem reviews/warranties, MediaAsset Job Capture photos); Financing and
 * Team gate on intake flags that aren't modeled yet, so they default closed (a clean seam —
 * flip them on when the intake data lands). Fixed pages are never gated.
 *
 * Thresholds are config-driven (`config/launchpad.php` → standard_pages).
 */
class StandardPageGate
{
    /** Whether an optional page is offerable for the site (fixed pages are always available). */
    public function isAvailable(Site $site, StandardPageType $type): bool
    {
        if ($type->isFixed()) {
            return true;
        }

        return match ($type) {
            StandardPageType::Reviews => $this->reviewCount($site) >= (int) config('launchpad.standard_pages.reviews_min', 1),
            StandardPageType::Gallery => $this->photoCount($site) >= (int) config('launchpad.standard_pages.gallery_min', 3),
            StandardPageType::Warranty => $this->hasWarrantyProof($site),
            StandardPageType::WhyChooseUs, StandardPageType::Faq => true, // VoiceKit / generated — always available
            StandardPageType::Financing => $this->intakeFlag($site, 'financing'),
            StandardPageType::Team => $this->intakeFlag($site, 'team'),
            default => false,
        };
    }

    /**
     * The optional types currently offerable for the site.
     *
     * @return list<StandardPageType>
     */
    public function offerable(Site $site): array
    {
        return array_values(array_filter(
            StandardPageType::optional(),
            fn (StandardPageType $t) => $this->isAvailable($site, $t),
        ));
    }

    private function reviewCount(Site $site): int
    {
        return ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('type', [ProofType::ReviewAggregate->value, ProofType::Testimonial->value])
            ->count();
    }

    private function photoCount(Site $site): int
    {
        return MediaAsset::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', MediaKind::Photo->value)
            ->where('source', MediaSource::Uploaded->value)
            ->count();
    }

    private function hasWarrantyProof(Site $site): bool
    {
        return ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('type', [ProofType::Warranty->value, ProofType::Guarantee->value])
            ->exists();
    }

    /**
     * Intake flags not yet modeled (financing, team) — default closed. Reads the site's
     * SetupState build config as the interim seam so an operator can opt in until the real
     * intake fields land.
     */
    private function intakeFlag(Site $site, string $flag): bool
    {
        $state = $site->setupState;

        return $state !== null && is_array($state->intake_flags) && (bool) ($state->intake_flags[$flag] ?? false);
    }
}
