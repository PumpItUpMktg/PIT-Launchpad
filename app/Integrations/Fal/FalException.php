<?php

namespace App\Integrations\Fal;

use RuntimeException;

/**
 * A normalized fal failure — timeout, transport error, or a non-success
 * response. The render job treats it as a bounded-retry failure, never letting
 * a raw provider error or a hang propagate.
 */
class FalException extends RuntimeException {}
