<?php

namespace App\Integrations\Wordpress;

use RuntimeException;

/**
 * A normalized failure talking to the companion plugin's REST contract — auth
 * rejected, or a non-success response after retries. Carries no credentials.
 */
class WordpressException extends RuntimeException {}
