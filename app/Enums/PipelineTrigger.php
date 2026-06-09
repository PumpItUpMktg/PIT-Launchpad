<?php

namespace App\Enums;

/**
 * Why a §5 pipeline refresh ran — logged as run-provenance (structured logging,
 * not a domain event). `scheduled` is the cadence-gated console run; `manual` is
 * the operator "refresh now" action (which also bypasses the cadence window).
 */
enum PipelineTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
