<?php

namespace App\JobCapture\Capture;

use RuntimeException;

/**
 * Thrown when an operator's typed address can't be geocoded to a point — a manual job can't be placed (and
 * so can't resolve its city/county), so intake stops with a message the surface shows rather than saving a
 * location-less job.
 */
final class CouldNotPlaceJobException extends RuntimeException {}
