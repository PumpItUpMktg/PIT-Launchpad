<?php

namespace App\Enums;

/**
 * The shape of a slot's content. Drives content-type validation of a payload
 * value and, at render time (§2), which lp/* dynamic tag reads the slot.
 */
enum SlotContentType: string
{
    case Heading = 'heading';
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case RichText = 'rich_text';
    case List = 'list';
    case Faq = 'faq';
    case Cta = 'cta';
    case Stat = 'stat';
    case Testimonial = 'testimonial';
    case Image = 'image';
    case Gallery = 'gallery';
    case Map = 'map';

    /**
     * Content types whose payload value is plain or rich inline text.
     */
    public function isText(): bool
    {
        return in_array($this, [self::Heading, self::ShortText, self::LongText, self::RichText], true);
    }
}
