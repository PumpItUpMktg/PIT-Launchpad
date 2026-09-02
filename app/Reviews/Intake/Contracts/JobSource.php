<?php

namespace App\Reviews\Intake\Contracts;

use App\Reviews\Intake\CompletedJob;
use App\Reviews\Intake\ManualJobSource;

/**
 * A source of completed jobs to request a review for (Review Capture §3). This is the ONLY seam an integration
 * plugs into: a new upstream (a future Joby driver, a CSV feed, anything) is added by writing one class that
 * implements this and registering the binding — nothing elsewhere in the module changes (§12 acceptance 9).
 *
 * `pending()` is the pull contract for a polling/webhook-buffered driver. A push driver (the operator
 * {@see ManualJobSource}) returns an empty iterable and issues requests on demand instead.
 */
interface JobSource
{
    /** @return iterable<CompletedJob> */
    public function pending(): iterable;
}
