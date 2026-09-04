<?php

namespace App\Enums;

use App\Operator\Lobby\LobbyCard;

/**
 * The four presentation states of an operator-lobby card ({@see LobbyCard}).
 */
enum LobbyCardState: string
{
    case Blocked = 'blocked';           // a Tier-1 badge present — red border, publishing blocked
    case ActivePending = 'active_pending'; // active with work waiting (Tier 2-4 badges)
    case ActiveClean = 'active_clean';   // active, nothing waiting — collapses to a pill row
    case Onboarding = 'onboarding';      // still in setup — progress bar, no operational badges
}
