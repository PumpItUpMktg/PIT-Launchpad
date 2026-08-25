<?php

namespace App\Geo;

use App\Enums\GeoPromptSource;
use App\Integrations\Claude\ClaudeClient;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Assisted weakness top-ups (GEO Phase 3): for the site's ABSENT prompts — measured but cited by no engine —
 * ask Claude (Haiku) for extra, natural ways a real homeowner would pose the same question, plus a
 * head-to-head vs the competitor that's winning. The variety separates "we phrased it oddly" from a genuine
 * absence, and gives the coverage matrix more signal. Variants are created `source=assisted`, tagged with the
 * parent's service/town/intent, active (the operator can prune them). Neutral by construction — anything
 * that names the brand is dropped (a brand-leading prompt fabricates a win). Bounded + idempotent.
 */
class GeoPromptTopUp
{
    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * @return array{created: int, gaps_addressed: int}
     */
    public function topUp(Site $site): array
    {
        $maxGaps = max(0, (int) config('launchpad.geo.topup.max_gaps', 8));
        $maxPerGap = max(1, (int) config('launchpad.geo.topup.max_variants_per_gap', 2));
        $maxPrompts = max(0, (int) config('launchpad.geo.topup.max_prompts', 20));
        $brand = trim((string) $site->brand_name);

        $prompts = GeoPrompt::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('active', true)->with(['service', 'coverageArea'])->get();

        $latest = [];   // [prompt_id][engine] => snapshot
        foreach (GeoSnapshot::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)
            ->whereIn('geo_prompt_id', $prompts->pluck('id'))->orderByDesc('checked_at')
            ->get(['geo_prompt_id', 'engine', 'cited', 'competitors']) as $s) {
            $latest[$s->geo_prompt_id][$s->engine] ??= $s;
        }

        // Absent = measured but cited in no engine. Biggest-town first (mirrors the gap ranking).
        $absent = $prompts->filter(function (GeoPrompt $p) use ($latest): bool {
            $rows = $latest[$p->id] ?? [];

            return $rows !== [] && ! collect($rows)->contains(fn ($s): bool => (bool) $s->cited);
        })->sortBy(fn (GeoPrompt $p): int => $this->tierRank($p->size_tier?->value))->values()->take($maxGaps);

        $texts = [];
        foreach ($prompts as $p) {
            $texts[mb_strtolower(trim((string) $p->prompt))] = true;
        }

        $created = 0;
        $addressed = 0;
        foreach ($absent as $p) {
            if ($created >= $maxPrompts) {
                break;
            }
            $competitors = collect($latest[$p->id] ?? [])->flatMap(fn ($s): array => (array) $s->competitors)
                ->map(fn ($c): string => trim((string) $c))->filter()->unique()->values()->all();

            $variants = $this->generate($brand, $p, $competitors, $maxPerGap);
            $addressed++;

            foreach ($variants as $text) {
                if ($created >= $maxPrompts) {
                    break;
                }
                $key = mb_strtolower(trim($text));
                if ($key === '' || isset($texts[$key])) {
                    continue;
                }
                GeoPrompt::create([
                    'site_id' => $site->id,
                    'service_id' => $p->service_id,
                    'coverage_area_id' => $p->coverage_area_id,
                    'size_tier' => $p->size_tier?->value,
                    'intent' => $p->intent?->value,
                    'source' => GeoPromptSource::Assisted->value,
                    'prompt' => $text,
                    'label' => trim((string) ($p->label ?? '').' · variant', ' ·'),
                    'active' => true,
                ]);
                $texts[$key] = true;
                $created++;
            }
        }

        return ['created' => $created, 'gaps_addressed' => $addressed];
    }

    /**
     * @param  list<string>  $competitors
     * @return list<string>
     */
    private function generate(string $brand, GeoPrompt $prompt, array $competitors, int $n): array
    {
        $data = $this->parse($this->claude->complete($this->prompt($brand, $prompt, $competitors, $n), $this->system()));

        $brandLc = mb_strtolower($brand);
        $out = [];
        foreach ((array) ($data['variants'] ?? []) as $v) {
            $text = trim((string) $v);
            // Drop empties and anything naming our brand — variants must stay neutral / demand-shaped.
            if ($text === '' || ($brandLc !== '' && str_contains(mb_strtolower($text), $brandLc))) {
                continue;
            }
            $out[$text] = true;
        }

        return array_slice(array_keys($out), 0, $n);
    }

    /**
     * @param  list<string>  $competitors
     */
    private function prompt(string $brand, GeoPrompt $prompt, array $competitors, int $n): string
    {
        $service = trim((string) $prompt->service?->name);
        $place = trim((string) $prompt->coverageArea?->name);
        $comp = $competitors === [] ? '(none observed)' : implode(', ', $competitors);

        return "A home-services brand is NOT appearing in AI search answers for this question:\n\"{$prompt->prompt}\"\n\n"
            .($service !== '' ? "Service: {$service}\n" : '')
            .($place !== '' ? "Area: {$place}\n" : '')
            ."Competitors currently cited: {$comp}\n\n"
            ."Write {$n} ALTERNATIVE ways a real homeowner might ask this same question in an AI assistant — natural, "
            .'varied phrasings and angles a person would actually type (you may include at most one head-to-head '
            .'comparison naming a listed competitor). Keep them neutral and demand-shaped: DO NOT mention the brand '
            .'being evaluated, and do not lead the answer. Return ONLY JSON: {"variants":["...","..."]}.';
    }

    private function system(): string
    {
        return 'You write realistic consumer search prompts for measuring AI-search visibility. Return strict JSON only.';
    }

    private function tierRank(?string $tier): int
    {
        return match ($tier) {
            'major' => 0, 'large' => 1, 'medium' => 2, 'small' => 3,
            default => 4,
        };
    }

    /** @return array<string, mixed> */
    private function parse(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
