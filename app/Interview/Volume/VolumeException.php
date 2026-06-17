<?php

namespace App\Interview\Volume;

use RuntimeException;

/**
 * Thrown when the volume grounding cannot run — no candidate spokes, no covered
 * metros, no head keywords, or every metro query failed. Fails loud rather than
 * silently writing zero volume.
 */
final class VolumeException extends RuntimeException {}
