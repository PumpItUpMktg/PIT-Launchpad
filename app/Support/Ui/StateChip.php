<?php

namespace App\Support\Ui;

use App\Enums\ContentStatus;
use App\Enums\ReviewFlag;
use App\Enums\SiteStatus;
use BackedEnum;

/**
 * The single source of the state-chip vocabulary — the ONE place a state value becomes a
 * (label, tone) chip. Before this, ~20 blades each styled their own status pill with hand-picked
 * colors and casing; the {@see \App\View\Components\Lp\Chip} component funnels every one of them
 * through here so the palette and wording are decided once.
 *
 * Callers pass a known enum ({@see ContentStatus}, {@see SiteStatus}, {@see ReviewFlag}) or a raw
 * state string; anything unrecognized degrades to a neutral chip with a humanized label (never a
 * crash, never an un-styled leak).
 */
class StateChip
{
    /**
     * Resolve a state to its chip label + tone.
     *
     * @return array{label: string, tone: ChipTone}
     */
    public static function resolve(BackedEnum|string $state): array
    {
        if ($state instanceof ContentStatus) {
            return self::content($state);
        }
        if ($state instanceof SiteStatus) {
            return self::site($state);
        }
        if ($state instanceof ReviewFlag) {
            return ['label' => $state->label(), 'tone' => self::flagTone($state)];
        }
        if ($state instanceof BackedEnum) {
            $state = (string) $state->value;
        }

        // A raw string — try the two status vocabularies by value, else a neutral fallback.
        return ContentStatus::tryFrom($state) !== null ? self::content(ContentStatus::from($state))
            : (SiteStatus::tryFrom($state) !== null ? self::site(SiteStatus::from($state))
                : ['label' => self::humanize($state), 'tone' => ChipTone::Neutral]);
    }

    /** @return array{label: string, tone: ChipTone} */
    private static function content(ContentStatus $s): array
    {
        return match ($s) {
            ContentStatus::Candidate => ['label' => 'Candidate', 'tone' => ChipTone::Info],
            ContentStatus::Scored => ['label' => 'Scored', 'tone' => ChipTone::Info],
            ContentStatus::Drafted => ['label' => 'Drafted', 'tone' => ChipTone::Info],
            ContentStatus::NeedsReview => ['label' => 'Needs review', 'tone' => ChipTone::Warn],
            ContentStatus::InReview => ['label' => 'In review', 'tone' => ChipTone::Warn],
            ContentStatus::Approved => ['label' => 'Approved', 'tone' => ChipTone::Good],
            ContentStatus::Rendering => ['label' => 'Rendering', 'tone' => ChipTone::Info],
            ContentStatus::Publishing => ['label' => 'Publishing', 'tone' => ChipTone::Info],
            ContentStatus::Published => ['label' => 'Published', 'tone' => ChipTone::Good],
            ContentStatus::RenderFailed => ['label' => 'Render failed', 'tone' => ChipTone::Bad],
            ContentStatus::PublishFailed => ['label' => 'Publish failed', 'tone' => ChipTone::Bad],
            ContentStatus::Rejected => ['label' => 'Rejected', 'tone' => ChipTone::Neutral],
        };
    }

    /** @return array{label: string, tone: ChipTone} */
    private static function site(SiteStatus $s): array
    {
        return match ($s) {
            SiteStatus::Onboarding => ['label' => 'Onboarding', 'tone' => ChipTone::Warn],
            SiteStatus::Active => ['label' => 'Active', 'tone' => ChipTone::Info],
            SiteStatus::Building => ['label' => 'Building', 'tone' => ChipTone::Info],
            SiteStatus::Live => ['label' => 'Live', 'tone' => ChipTone::Good],
            SiteStatus::Suspended => ['label' => 'Suspended', 'tone' => ChipTone::Bad],
        };
    }

    private static function flagTone(ReviewFlag $flag): ChipTone
    {
        return match ($flag) {
            ReviewFlag::RenderFailed, ReviewFlag::BrandSafety => ChipTone::Bad,
            ReviewFlag::UnsupportedClaim, ReviewFlag::NearDuplicate => ChipTone::Warn,
            ReviewFlag::OnDemand => ChipTone::Info,
            ReviewFlag::RelevanceBand => ChipTone::Neutral,
        };
    }

    private static function humanize(string $value): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $value));
    }
}
