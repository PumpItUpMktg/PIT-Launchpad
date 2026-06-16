<?php

namespace App\Enums;

/**
 * The content source a compose/publish runs from. `Generated` is the real authored
 * content; `Placeholder` swaps in length-representative stand-ins (+ image/form
 * boxes) through the SAME composer/surface/brand tokens — a faithful preview for
 * design evaluation (preview = reality, only slot content differs).
 */
enum ContentSource: string
{
    case Generated = 'generated';
    case Placeholder = 'placeholder';
}
