<?php

namespace App\PageBuilder\Validation;

/**
 * Structured reason codes for a validation failure.
 */
enum ValidationCode: string
{
    case MissingRequiredSlot = 'missing_required_slot';
    case EmptyRequiredSlot = 'empty_required_slot';
    case LengthBelowMinimum = 'length_below_minimum';
    case LengthAboveMaximum = 'length_above_maximum';
    case CardinalityBelowMinimum = 'cardinality_below_minimum';
    case CardinalityAboveMaximum = 'cardinality_above_maximum';
    case ContentTypeMismatch = 'content_type_mismatch';
    case EntityUnresolved = 'entity_unresolved';
    case EntityBelowMinimum = 'entity_below_minimum';
    case MediaMissing = 'media_missing';
    case MediaSizeBelowMinimum = 'media_size_below_minimum';
    case MediaAltMissing = 'media_alt_missing';
    case ThinPage = 'thin_page';
    case LocationMarketMissing = 'location.market_missing';
}
