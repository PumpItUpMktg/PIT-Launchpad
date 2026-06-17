<?php

namespace App\Interview\Expansion;

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeTag;

/**
 * Closed-schema validation of the expansion's model output. A passing payload is
 * guaranteed buildable into a well-formed candidate tree. Enforces the calibration
 * invariants: at least one silo, every silo has spokes, every spoke has a name + head
 * keyword + valid tag/page_type/granularity, and a `connecting` spoke always states
 * its connection. Returns human-readable errors; empty means valid.
 */
final class ExpansionValidator
{
    /**
     * @return list<string>
     */
    public function validate(mixed $payload): array
    {
        if (! is_array($payload)) {
            return ['Output is not a JSON object.'];
        }

        $errors = [];

        $silos = $payload['silos'] ?? null;
        if (! is_array($silos) || $silos === []) {
            $errors[] = 'Missing "silos" — expected at least one proposed silo.';
        } else {
            foreach (array_values($silos) as $i => $silo) {
                $this->validateSilo($silo, $i, $errors);
            }
        }

        if (array_key_exists('fringe_handoff', $payload)) {
            if (! is_array($payload['fringe_handoff'])) {
                $errors[] = 'fringe_handoff must be a list.';
            } else {
                foreach (array_values($payload['fringe_handoff']) as $i => $fringe) {
                    if (! is_array($fringe) || trim((string) ($fringe['name'] ?? '')) === '') {
                        $errors[] = "fringe_handoff[{$i}].name is empty.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate the decompose PLAN pass: silo headers only (name required), no spokes yet.
     *
     * @return list<string>
     */
    public function validatePlan(mixed $payload): array
    {
        if (! is_array($payload)) {
            return ['Output is not a JSON object.'];
        }

        $silos = $payload['silos'] ?? null;
        if (! is_array($silos) || $silos === []) {
            return ['Missing "silos" — expected at least one planned silo.'];
        }

        $errors = [];
        foreach (array_values($silos) as $i => $silo) {
            if (! is_array($silo) || trim((string) ($silo['name'] ?? '')) === '') {
                $errors[] = "silos[{$i}].name is empty.";
            }
        }

        return $errors;
    }

    /**
     * Validate the decompose PER-SILO pass: a {"spokes":[...]} payload for one silo.
     *
     * @return list<string>
     */
    public function validateSpokes(mixed $payload): array
    {
        if (! is_array($payload)) {
            return ['Output is not a JSON object.'];
        }

        $spokes = $payload['spokes'] ?? null;
        if (! is_array($spokes) || $spokes === []) {
            return ['Missing "spokes" — expected at least one spoke for the silo.'];
        }

        $errors = [];
        foreach (array_values($spokes) as $j => $spoke) {
            $this->validateSpoke($spoke, 'silo', $j, $errors);
        }

        return $errors;
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateSilo(mixed $silo, int $i, array &$errors): void
    {
        if (! is_array($silo)) {
            $errors[] = "silos[{$i}] is not an object.";

            return;
        }

        $label = trim((string) ($silo['name'] ?? '')) ?: "#{$i}";
        if (trim((string) ($silo['name'] ?? '')) === '') {
            $errors[] = "silos[{$i}].name is empty.";
        }

        $spokes = $silo['spokes'] ?? null;
        if (! is_array($spokes) || $spokes === []) {
            $errors[] = "silo \"{$label}\" has no spokes.";

            return;
        }

        foreach (array_values($spokes) as $j => $spoke) {
            $this->validateSpoke($spoke, $label, $j, $errors);
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateSpoke(mixed $spoke, string $siloLabel, int $j, array &$errors): void
    {
        $at = "silo \"{$siloLabel}\" spoke[{$j}]";

        if (! is_array($spoke)) {
            $errors[] = "{$at} is not an object.";

            return;
        }

        if (trim((string) ($spoke['name'] ?? '')) === '') {
            $errors[] = "{$at}.name is empty.";
        }
        if (trim((string) ($spoke['head_keyword'] ?? '')) === '') {
            $errors[] = "{$at}.head_keyword is empty.";
        }

        $tag = SpokeTag::tryFrom(EnumNormalizer::normalize($spoke['tag'] ?? ''));
        if ($tag === null) {
            $errors[] = "{$at}.tag is not one of core/adjacent/connecting/fringe.";
        }
        if (SpokePageType::tryFrom(EnumNormalizer::normalize($spoke['page_type'] ?? '')) === null) {
            $errors[] = "{$at}.page_type is not service/content.";
        }
        if (array_key_exists('granularity', $spoke)
            && SpokeGranularity::tryFrom(EnumNormalizer::normalize($spoke['granularity'])) === null) {
            $errors[] = "{$at}.granularity is not own_page/folded.";
        }

        // A connecting spoke must state the problem-chain connection.
        if ($tag === SpokeTag::Connecting && trim((string) ($spoke['connection_note'] ?? '')) === '') {
            $errors[] = "{$at} is tagged connecting but has no connection_note.";
        }
    }
}
