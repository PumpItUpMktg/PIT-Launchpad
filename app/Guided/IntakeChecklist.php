<?php

namespace App\Guided;

use App\Enums\ProofType;
use App\Models\Location;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteNarrative;
use App\Publishing\SiteContact;
use App\Support\BusinessHours;

/**
 * The Grow workbench's pre-publish CONTENT CHECKLIST — every intake input the page sections
 * data-gate on, with whether it's captured, what it unlocks, and where to add it. Pages publish
 * fine without these (degrade by omission is the design — an empty section is left out, never
 * fabricated); the checklist makes the gaps LOUD before the client publishes, so "why is my
 * mission not showing?" is answered on the page they publish from, not after the fact.
 *
 * Read-only: derives everything from §1 (SiteNarrative / ProofItem / Location / the site flags).
 */
final class IntakeChecklist
{
    public function __construct(private readonly SiteContact $contact) {}

    /**
     * Only the MISSING items — what the Grow page surfaces.
     *
     * @return list<array{key: string, label: string, unlocks: string, where: string}>
     */
    public function missing(Site $site): array
    {
        return array_values(array_filter(
            $this->all($site),
            fn (array $item): bool => ! $item['captured'],
        ));
    }

    /**
     * Every checklist item with its captured state.
     *
     * @return list<array{key: string, label: string, captured: bool, unlocks: string, where: string}>
     */
    public function all(Site $site): array
    {
        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->first();

        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->first();

        $has = fn (string $field): bool => $this->narrativeHas($narrative, $field);

        $guarantee = is_array($narrative?->guarantee) ? $narrative->guarantee : [];

        return [
            $this->item('story', 'Your brand story', $has('story'),
                'the About page — it HOLDS until a story is captured', 'Setup → Brand'),
            $this->item('mission', 'Mission statement', $has('mission'),
                'the mission band on About', 'Setup → Brand'),
            $this->item('values', 'Values', $has('values'),
                'the "Our promises to you" grid on About', 'Setup → Brand'),
            $this->item('differentiators', 'What sets you apart', $has('differentiators'),
                'the reason cards on Why Choose Us, Home, and About', 'Setup → Brand'),
            $this->item('guarantee', 'Guarantee / warranty', trim((string) ($guarantee['name'] ?? '')) !== '',
                'the guarantee band on Home and Why Choose Us', 'Setup → Business'),
            $this->item('certifications', 'Licenses & certifications', $has('certifications'),
                'the credentials row and the trust badges', 'Setup → Business'),
            $this->item('team', 'Team members', $has('team'),
                'the team grid on About (real faces build the most trust)', 'Setup → Brand'),
            $this->item('reviews', 'Reviews / testimonials', $this->hasReviews($site),
                'the "In their words" sections across the site', 'Setup → Business'),
            $this->item('phone', 'Business phone', $this->contact->phone($site) !== null,
                'every call button and click-to-call link', 'Setup → Business'),
            $this->item('email', 'Business email', trim((string) ($location->email ?? '')) !== '',
                'the Contact page email', 'Setup → Business'),
            $this->item('address', 'Business address', trim((string) ($location->address ?? '')) !== '',
                'the Contact address + map pin (shown only when customers visit you)', 'Setup → Business'),
            $this->item('hours', 'Business hours', $this->hasHours($location),
                'the hours block on Contact', 'Setup → Business'),
        ];
    }

    /** @return array{key: string, label: string, captured: bool, unlocks: string, where: string} */
    private function item(string $key, string $label, bool $captured, string $unlocks, string $where): array
    {
        return ['key' => $key, 'label' => $label, 'captured' => $captured, 'unlocks' => $unlocks, 'where' => $where];
    }

    private function narrativeHas(?SiteNarrative $narrative, string $field): bool
    {
        $value = $narrative?->getAttribute($field);

        return is_array($value) ? $value !== [] : (is_string($value) && trim($value) !== '');
    }

    private function hasReviews(Site $site): bool
    {
        return ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('is_substantiated', true)
            ->whereIn('type', [ProofType::Testimonial->value, ProofType::ReviewAggregate->value])
            ->exists();
    }

    private function hasHours(?Location $location): bool
    {
        foreach (BusinessHours::fromStored(is_array($location?->hours) ? $location->hours : null) as $row) {
            if ($row['all_day'] || (! $row['closed'] && trim((string) $row['open']) !== '')) {
                return true;
            }
        }

        return false;
    }
}
