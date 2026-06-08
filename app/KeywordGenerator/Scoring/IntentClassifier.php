<?php

namespace App\KeywordGenerator\Scoring;

use App\Enums\IntentLevel;

/**
 * Resolves a query's conversion intent — from an explicit intake hint when it
 * maps cleanly, otherwise inferred from query signals.
 */
class IntentClassifier
{
    private const TRANSACTIONAL = ['repair', 'replace', 'install', 'service', 'fix', 'hire', 'emergency', 'near me'];

    private const COMMERCIAL = ['cost', 'price', 'quote', 'best', 'cheap', 'company', 'contractor'];

    private const INFORMATIONAL = ['how to', 'how do', 'what is', 'why', 'signs', 'guide', 'tips', 'vs'];

    public function classify(string $query, ?string $hint = null): IntentLevel
    {
        $mapped = $this->fromHint($hint);
        if ($mapped !== null) {
            return $mapped;
        }

        $haystack = mb_strtolower($query);

        foreach (self::INFORMATIONAL as $signal) {
            if (str_contains($haystack, $signal)) {
                return IntentLevel::Informational;
            }
        }
        foreach (self::TRANSACTIONAL as $signal) {
            if (str_contains($haystack, $signal)) {
                return IntentLevel::Transactional;
            }
        }
        foreach (self::COMMERCIAL as $signal) {
            if (str_contains($haystack, $signal)) {
                return IntentLevel::Commercial;
            }
        }

        return IntentLevel::Informational;
    }

    private function fromHint(?string $hint): ?IntentLevel
    {
        if ($hint === null) {
            return null;
        }

        return IntentLevel::tryFrom(mb_strtolower(trim($hint)));
    }
}
