<?php

namespace App\Gathering;

use App\Integrations\Claude\ClaudeClient;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;

/**
 * The opt-in AI enrichment call for one service — fills the enrichment scaffolding
 * (short description, symptoms, scope, process, cost factors) with GENERIC trade knowledge
 * the operator then reviews and edits. Honesty rules, hard-coded:
 *
 *  - **Fills blanks only.** A field with ANY existing value — operator-typed, interview-seeded,
 *    or previously AI-filled — is never touched; manual entry always wins.
 *  - **Never business facts.** Price range, warranty, and the comparison block are excluded by
 *    design (business-specific — the owner supplies those); the prompt forbids invented prices,
 *    guarantees, and brand claims.
 *  - **Seeded, not confirmed.** Every filled field gets a seeded provenance row — the operator's
 *    review-and-save on the enrichment form flips it to confirmed (the step's existing contract).
 *
 * Fail-open: a thrown call or unparseable reply returns null and writes nothing.
 */
class ServiceEnricher
{
    /** The generic-safe enrichment fields the AI may fill (list fields + the one-liner). */
    public const FIELDS = ['short_description', 'symptoms', 'scope_items', 'process_steps', 'cost_factors'];

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly Provenance $provenance,
    ) {}

    /**
     * @return list<string>|null the fields actually filled; [] when nothing was empty; null on AI failure
     */
    public function enrich(Site $site, Service $service): ?array
    {
        $empty = array_values(array_filter(self::FIELDS, fn (string $f) => $this->isEmpty($service->{$f})));
        if ($empty === []) {
            return [];
        }

        $data = $this->parse($this->safeComplete($this->prompt($site, $service, $empty), $this->system($site)));
        if ($data === null) {
            return null;
        }

        $filled = [];
        foreach ($empty as $field) {
            $value = $this->clean($field, $data[$field] ?? null);
            if ($value === null) {
                continue;
            }
            $service->{$field} = $value;
            $filled[] = $field;
        }

        if ($filled === []) {
            return null; // a reply that filled nothing usable is a failure, not a success
        }

        $service->save();
        foreach ($filled as $field) {
            $this->provenance->seed($service, $field);
        }

        return $filled;
    }

    private function system(Site $site): string
    {
        $trade = (string) (SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->value('trade') ?? '');
        $tradeLine = $trade !== '' ? " in the {$trade} trade" : '';

        return "You enrich a home-services business's service definitions{$tradeLine} with GENERIC trade knowledge. "
            .'Plain language a homeowner understands. NEVER invent business-specific facts: no prices or price ranges, '
            .'no guarantees or warranties, no brand names, no service-area or credential claims.';
    }

    /** @param  list<string>  $fields */
    private function prompt(Site $site, Service $service, array $fields): string
    {
        $specs = [
            'short_description' => '"short_description": one plain sentence describing the service (for a card)',
            'symptoms' => '"symptoms": 4-6 short "signs you need this" bullets',
            'scope_items' => '"scope_items": 4-6 "what\'s included" items',
            'process_steps' => '"process_steps": 3-6 ordered steps, homeowner-facing',
            'cost_factors' => '"cost_factors": 3-5 things that drive the price (factors only, no amounts)',
        ];
        $wanted = implode("\n", array_map(fn (string $f) => '- '.$specs[$f], $fields));

        return "Service: {$service->name}\n\nFill ONLY these fields:\n{$wanted}\n\n"
            .'Respond as ONE JSON object with exactly those keys (strings, or arrays of short strings). JSON only, no prose.';
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
     * Fence/prose-tolerant parse to the reply object.
     *
     * @return array<string, mixed>|null
     */
    private function parse(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/\{.*\}/s', $raw, $m) === 1) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Normalize one field's reply value to its column shape — null when unusable. */
    private function clean(string $field, mixed $value): string|array|null
    {
        if ($field === 'short_description') {
            $text = trim((string) (is_string($value) ? $value : ''));

            return $text === '' ? null : $text;
        }

        if (! is_array($value)) {
            return null;
        }
        $items = array_values(array_filter(array_map(fn ($v) => trim((string) (is_string($v) ? $v : '')), $value), fn (string $v) => $v !== ''));

        return $items === [] ? null : $items;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);
    }
}
