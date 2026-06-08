<?php

namespace App\Enums;

/**
 * A site's lifecycle state. The transition to Live is guarded by the
 * SiteLaunchGate — a site cannot go live while any pilot-exposed credential is
 * unrotated or a platform secret lacks its post-pilot attestation.
 */
enum SiteStatus: string
{
    // The §7a onboarding wizard's initial lifecycle state, flipped to Active
    // once the intake is complete and the launch gate is satisfied.
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Building = 'building';
    case Live = 'live';
    case Suspended = 'suspended';
}
