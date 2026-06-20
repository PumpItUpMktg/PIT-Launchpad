<?php

namespace App\Guided;

use App\Integrations\Claude\ClaudeClient;
use Illuminate\Support\Str;

/**
 * Step 1's connecting-services suggester: given a trade + the services the owner has already
 * stated, propose the services a business in that trade *commonly also offers* — ranked by how
 * common they are, **service-intent only** (real services, not informational topics) and **no
 * volume** (that's Phase 3, after the structure exists). Advisory: a confirmed suggestion
 * becomes a stated service. Grounded through the {@see ClaudeClient} seam (faked in tests); a
 * parse/again failure returns an empty list — never fatal to the flow.
 */
class ServiceSuggester
{
    public function __construct(
        private readonly ClaudeClient $claude,
    ) {}

    /**
     * @param  list<string>  $statedServices
     * @return list<array{name: string, why: string}>
     */
    public function suggest(string $trade, array $statedServices, int $limit = 6): array
    {
        $trade = trim($trade);
        if ($trade === '') {
            return [];
        }

        $system = 'You help a home-services business find services it commonly offers but forgot to list. '
            .'Return ONLY real, bookable services (service intent) — never informational topics, brands, or locations. '
            .'Rank by how commonly a business in this trade also offers them (most common first).';

        $stated = implode(', ', $statedServices) ?: '(none yet)';
        $prompt = "Trade: {$trade}\nAlready listed: {$stated}\n\n"
            ."Suggest up to {$limit} additional services this business likely also offers, excluding anything already listed. "
            .'Respond as a JSON array of objects with "name" (the service) and "why" (a short, plain-language reason, ~6 words). '
            .'JSON only, no prose.';

        $parsed = $this->parse($this->safeComplete($prompt, $system));

        // Drop anything already stated (case/space-insensitive), de-dupe, cap.
        $seen = [];
        foreach ($statedServices as $s) {
            $seen[$this->norm($s)] = true;
        }

        $out = [];
        foreach ($parsed as $row) {
            $key = $this->norm($row['name']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function safeComplete(string $prompt, string $system): string
    {
        try {
            return $this->claude->complete($prompt, $system);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Fence/prose-tolerant parse to a list of {name, why}.
     *
     * @return list<array{name: string, why: string}>
     */
    private function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Pull the first JSON array out of any fences/prose.
        if (preg_match('/\[.*\]/s', $raw, $m) === 1) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = ['name' => $name, 'why' => trim((string) ($row['why'] ?? ''))];
        }

        return $out;
    }

    private function norm(string $value): string
    {
        return Str::of($value)->lower()->squish()->value();
    }
}
