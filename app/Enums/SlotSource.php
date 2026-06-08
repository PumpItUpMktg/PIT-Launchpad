<?php

namespace App\Enums;

/**
 * Where a slot's content comes from — the accuracy backbone. Makes accuracy
 * structural rather than prompt-dependent.
 */
enum SlotSource: string
{
    /** Claude writes it (voice + framing). */
    case Generated = 'generated';
    /** Generated but constrained to an entity set (e.g. substantiated claims). */
    case Grounded = 'grounded';
    /** Bound to structured data, never generated. */
    case Entity = 'entity';
    /** Provided by the client at intake / Assets. */
    case Client = 'client';
    /** Image/video routed by the real-vs-FLUX policy; alt generated, R2-bound. */
    case Media = 'media';

    /**
     * Sources whose availability is resolved against §1 entity models
     * (rather than read straight from the generated payload).
     */
    public function resolvesAgainstEntities(): bool
    {
        return in_array($this, [self::Entity, self::Grounded], true);
    }
}
