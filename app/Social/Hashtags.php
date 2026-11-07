<?php

namespace App\Social;

/** `Sump Pump Repair` → `#SumpPumpRepair`; `Mt. Olive` + `NJ` → `#MtOliveNJ`. */
final class Hashtags
{
    /**
     * @param  list<string|null>  $terms
     * @return list<string>
     */
    public static function from(array $terms): array
    {
        $out = [];
        foreach ($terms as $term) {
            $tag = self::one((string) $term);
            if ($tag !== null && ! in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    public static function one(string $term): ?string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $term) ?: [];
        $tag = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $tag .= ucfirst(strtolower($word));
        }

        return $tag === '' || is_numeric($tag) ? null : '#'.$tag;
    }

    public static function town(?string $name, ?string $state): ?string
    {
        $tag = self::one(trim((string) $name));
        if ($tag === null) {
            return null;
        }

        return $tag.strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $state));
    }
}
