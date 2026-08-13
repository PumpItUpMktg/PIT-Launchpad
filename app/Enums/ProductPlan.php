<?php

namespace App\Enums;

/**
 * What a tenant is signed up for. `JobCapture` is the standalone entry product — a client who only runs the
 * field-tech capture → WordPress publish loop, onboarded through the short Job Capture path (business basics
 * + the SHARED WordPress connection + techs). `Launchpad` is the full platform. The plan is the upgrade
 * lever: a Job Capture client already lives in the exact shape Launchpad expects (same Site, same WP
 * connection), so upgrading is flipping this to `Launchpad` and running the rest of onboarding — never a
 * rebuild or a re-connect.
 */
enum ProductPlan: string
{
    case JobCapture = 'job_capture';
    case Launchpad = 'launchpad';

    public function label(): string
    {
        return match ($this) {
            self::JobCapture => 'Job Capture',
            self::Launchpad => 'Launchpad',
        };
    }

    /** Whether this tenant runs the full Launchpad platform (vs. standalone Job Capture). */
    public function isLaunchpad(): bool
    {
        return $this === self::Launchpad;
    }
}
