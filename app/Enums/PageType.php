<?php

namespace App\Enums;

use App\Publishing\LaunchOrchestrator;

enum PageType: string
{
    case Home = 'home';
    case Service = 'service';
    case Location = 'location';
    case Hub = 'hub';
    case Utility = 'utility';
    case Pillar = 'pillar';
    case Cluster = 'cluster';

    /**
     * The dependency-safe PUBLISH order — lower publishes first. Pages that LINK to other pages must
     * go live AFTER the pages they link to, because the "Our services" grid and internal links only
     * resolve to pages that are already on WordPress (`wp_post_id` set). So it's leaves-first,
     * indexes-last:
     *
     *   service spokes → cluster → hub/pillar (need their spokes live) → location (needs service
     *   pages live) → standard/utility (no page-card links) → HOME (links service + hub, so last).
     *
     * The single source of truth for {@see LaunchOrchestrator} and every bulk publish,
     * so a fresh launch never ships a Home whose service grid is empty because the spokes weren't live
     * yet. (Posts publish after all pages — the orchestrator already sequences kind=post last.)
     */
    public function publishRank(): int
    {
        return match ($this) {
            self::Service => 1,
            self::Cluster => 2,
            self::Hub, self::Pillar => 3,
            self::Location => 4,
            self::Utility => 5,
            self::Home => 6,
        };
    }
}
