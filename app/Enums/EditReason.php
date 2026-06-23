<?php

namespace App\Enums;

/**
 * Why an operator corrected generated content (§7). The tag is the whole game — it disambiguates a
 * picky edit from a real defect and maps straight to an action.
 */
enum EditReason: string
{
    case OffBase = 'off_base';      // factually off / wrong service / hallucinated claim → fix generation/grounding
    case OffBrand = 'off_brand';    // wrong voice / a guarantee we don't make / tone that isn't them → fix brand voice
    case Preference = 'preference'; // a fine page made finer → ignore as signal

    public function label(): string
    {
        return match ($this) {
            self::OffBase => 'Off-base',
            self::OffBrand => 'Off-brand',
            self::Preference => 'Preference',
        };
    }

    /** The action this tag points at (the read-across view's whole purpose). */
    public function action(): string
    {
        return match ($this) {
            self::OffBase => 'Fix generation / grounding',
            self::OffBrand => 'Fix brand voice',
            self::Preference => 'No action (operator preference)',
        };
    }
}
