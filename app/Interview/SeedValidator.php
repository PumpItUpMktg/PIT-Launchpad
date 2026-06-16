<?php

namespace App\Interview;

/**
 * Closed-schema validation of the extractor's model output. A passing payload is
 * guaranteed well-formed enough to build a SiloSeed + VoiceProfile from — so the
 * extractor can never emit garbage (fabricated trade, empty anchors, malformed
 * voice). Returns a list of human-readable errors; empty means valid.
 */
final class SeedValidator
{
    /**
     * @param  mixed  $payload  the decoded model output (expected: {seed:{…}, voice:{…}})
     * @return list<string> validation errors; [] when valid
     */
    public function validate(mixed $payload): array
    {
        if (! is_array($payload)) {
            return ['Output is not a JSON object.'];
        }

        $errors = [];

        $seed = $payload['seed'] ?? null;
        if (! is_array($seed)) {
            $errors[] = 'Missing "seed" object.';
        } else {
            if (trim((string) ($seed['trade'] ?? '')) === '') {
                $errors[] = 'seed.trade is empty.';
            }
            if (! $this->isNonEmptyStringList($seed['anchor_services'] ?? null)) {
                $errors[] = 'seed.anchor_services must list at least one service.';
            }
            if (! $this->isStringList($seed['markets'] ?? [])) {
                $errors[] = 'seed.markets must be a list of strings.';
            }
            if (! $this->isStringList($seed['exclusions'] ?? [])) {
                $errors[] = 'seed.exclusions must be a list of strings.';
            }
        }

        $voice = $payload['voice'] ?? null;
        if (! is_array($voice)) {
            $errors[] = 'Missing "voice" object.';
        } else {
            if (trim((string) ($voice['framing_model'] ?? '')) === '') {
                $errors[] = 'voice.framing_model is empty.';
            }
            if (! is_array($voice['tone_axes'] ?? null)) {
                $errors[] = 'voice.tone_axes must be an object.';
            }
        }

        return $errors;
    }

    private function isStringList(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                return false;
            }
        }

        return true;
    }

    private function isNonEmptyStringList(mixed $value): bool
    {
        if (! $this->isStringList($value) || ! is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (trim((string) $item) !== '') {
                return true;
            }
        }

        return false;
    }
}
