<?php

namespace App\JobCapture\Enhancement;

use RuntimeException;

/**
 * Thrown when enhancement produced no usable write-up (§7) — the model call failed or returned an empty
 * description. Mirrors the drafting invariant "no draft, no transition": the job is left re-enhanceable
 * rather than advanced to review with empty content, and the caller (queued job / command) surfaces it.
 */
class JobEnhancementException extends RuntimeException {}
