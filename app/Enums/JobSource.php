<?php

namespace App\Enums;

/**
 * How a Job Capture record entered the pipeline. `Manual` is the always-available path — a tech captures a
 * walk-in / phone-dispatched job that was never in Joby. `Joby` records arrive from the Joby.io integration
 * (§6): the job lands pre-populated (customer + verified address + job type) and the tech only adds photos
 * and a description. Manual stays a live, independent path regardless of Joby integration maturity.
 */
enum JobSource: string
{
    case Manual = 'manual';
    case Joby = 'joby';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Joby => 'Joby',
        };
    }
}
