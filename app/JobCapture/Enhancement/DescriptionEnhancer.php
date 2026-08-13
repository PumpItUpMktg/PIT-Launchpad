<?php

namespace App\JobCapture\Enhancement;

use App\Integrations\Claude\ClaudeClient;

/**
 * A lightweight, in-form counterpart to {@see JobEnhancer}: polishes an operator's rough "what was done"
 * notes into a few concrete, publish-ready sentences they can review and tweak BEFORE the job is created —
 * so the seed the pipeline enhances from is already clean. Text in, text out (no JSON, no persistence, no
 * status change), through the same swappable {@see ClaudeClient} seam (a fake client in tests). It never
 * invents facts — an empty note returns empty, and the model is told to ground every sentence in the notes.
 */
final class DescriptionEnhancer
{
    private const SYSTEM = 'You are an expert local-SEO copywriter for a home-services company. Rewrite the '
        .'operator’s rough notes about a REAL completed job into 2–4 concrete, specific sentences that read as '
        .'the company describing the work — never generic filler, never invented facts. Ground every sentence '
        .'in the notes provided. Reply with ONLY the rewritten text, no preamble, no quotes, no labels.';

    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * @param  list<string>  $serviceTypes  the services performed (context for the model)
     */
    public function enhance(string $notes, array $serviceTypes = [], ?string $location = null): string
    {
        $notes = trim($notes);
        if ($notes === '') {
            return '';
        }

        $types = trim(implode(', ', array_filter(array_map('trim', $serviceTypes))));
        $location = $location !== null ? trim($location) : '';

        $context = array_filter([
            $types !== '' ? "Service(s) performed: {$types}" : null,
            $location !== '' ? "Location served: {$location}" : null,
            "Operator’s notes (the source of truth — do not contradict or embellish beyond them): {$notes}",
        ]);

        $prompt = implode("\n", [
            'Rewrite the operator’s notes below into 2–4 specific sentences documenting this completed job.',
            '',
            implode("\n", $context),
        ]);

        return trim($this->claude->complete($prompt, self::SYSTEM));
    }
}
