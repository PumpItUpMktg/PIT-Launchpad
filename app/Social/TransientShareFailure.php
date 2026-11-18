<?php

namespace App\Social;

use RuntimeException;

/**
 * The provider failed in a way worth one more try (a timeout, a 5xx, a per-minute rate limit) and the
 * send job still has an attempt left: the row stays queued and the job is retried after the backoff.
 * A refusal (policy, bad page token, the monthly allowance) is never this — it fails the row at once.
 */
final class TransientShareFailure extends RuntimeException {}
