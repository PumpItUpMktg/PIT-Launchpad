<?php

namespace App\Operator\Lobby;

use App\Enums\LobbyCardState;
use App\Models\Site;

/**
 * One tenant's lobby card — a pure view-model assembled by {@see LobbyBoard} in the single aggregated
 * pass (it triggers no query of its own). Carries the card state, its badges (already tier-ordered),
 * and the onboarding progress for a setup card. The card body links to the tenant's dashboard and each
 * badge to a filtered surface — both navigation only, wired by the Filament layer.
 */
final class LobbyCard
{
    /** Max badges shown on an active card before the rest collapse into "+N more". */
    private const MAX_ACTIVE_BADGES = 3;

    /**
     * @param  list<LobbyBadge>  $badges  every badge for this site, ordered most-urgent (lowest tier) first
     */
    public function __construct(
        public readonly Site $site,
        public readonly LobbyCardState $state,
        public readonly array $badges,
        public readonly int $onboardingStep = 0,
        public readonly int $onboardingStepCount = 0,
    ) {}

    public function brandName(): string
    {
        return trim((string) $this->site->brand_name) !== '' ? (string) $this->site->brand_name : 'Untitled tenant';
    }

    public function domain(): string
    {
        return (string) ($this->site->domain_url ?? '');
    }

    /**
     * The badges actually rendered. Blocked → only the single top Tier-1 badge (the blocker), the rest
     * suppressed. Active → up to three, tier-ordered. Clean/Onboarding → none.
     *
     * @return list<LobbyBadge>
     */
    public function visibleBadges(): array
    {
        return match ($this->state) {
            LobbyCardState::Blocked => array_slice($this->badges, 0, 1),
            LobbyCardState::ActivePending => array_slice($this->badges, 0, self::MAX_ACTIVE_BADGES),
            default => [],
        };
    }

    /** How many badges are hidden behind "+N more". */
    public function moreCount(): int
    {
        return max(0, count($this->badges) - count($this->visibleBadges()));
    }

    /** The overflow label — a blocked card explains why the rest are moot. */
    public function moreLabel(): ?string
    {
        if ($this->moreCount() === 0) {
            return null;
        }

        return $this->state === LobbyCardState::Blocked
            ? "+{$this->moreCount()} more, none publishable until the connection is fixed"
            : "+{$this->moreCount()} more";
    }

    /** Sort key for attention-rank order (higher = more urgent). */
    public function attentionScore(): int
    {
        $score = 0;
        foreach ($this->badges as $badge) {
            // Tier 1 dominates tier 2 dominates … regardless of counts, then counts break ties within a card.
            $score += (5 - $badge->tier->rank()) * 1000 + max(1, (int) $badge->count);
        }

        return $score;
    }

    public function needsAttention(): bool
    {
        return $this->badges !== [];
    }
}
