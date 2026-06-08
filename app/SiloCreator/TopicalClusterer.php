<?php

namespace App\SiloCreator;

use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Enums\SiloType;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\ServiceProblem;
use App\Models\Site;

/**
 * Claude-assisted pass: clusters the ServiceProblem inventory + seed keywords
 * into recurring advisory themes (prevention/maintenance, buying guides,
 * seasonal prep, cost/financing, safety/code, …). Each viable theme becomes a
 * proposed topical silo. The Claude call goes through the thin ClaudeClient
 * seam, so it is fully mockable in tests.
 */
class TopicalClusterer
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly RuleSetSeeder $seeder,
        private readonly ViabilityGuard $guard,
    ) {}

    /**
     * @return list<SiloProposal>
     */
    public function propose(Site $site): array
    {
        $problems = ServiceProblem::whereHas('service', fn ($q) => $q->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id))
            ->pluck('phrase')
            ->all();

        $seedKeywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('source', KeywordSource::Seed)
            ->pluck('query')
            ->all();

        if ($problems === [] && $seedKeywords === []) {
            return [];
        }

        $themes = $this->parse($this->claude->complete($this->prompt($problems, $seedKeywords), $this->system()));

        $proposals = [];
        foreach ($themes as $theme) {
            $name = trim((string) ($theme['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $terms = array_values(array_map('strval', $theme['terms'] ?? []));
            $themeProblems = array_values(array_map('strval', $theme['problems'] ?? []));
            $support = count($themeProblems) + count($theme['keywords'] ?? []);

            if (! $this->guard->isViable($support)) {
                continue;
            }

            $proposals[] = new SiloProposal(
                type: SiloType::Topical,
                name: $name,
                ruleSet: $this->seeder->forTheme($name, $terms, $themeProblems),
                source: 'topical',
                supportCount: $support,
                pillarPageType: PageType::Pillar,
            );
        }

        return $proposals;
    }

    /**
     * @param  list<string>  $problems
     * @param  list<string>  $seedKeywords
     */
    private function prompt(array $problems, array $seedKeywords): string
    {
        return 'Cluster the following home-services customer problems and seed keywords into recurring, geo-neutral advisory themes '
            .'(e.g. prevention/maintenance, buying guides, seasonal prep, cost/financing, safety/code). Do NOT include any city, '
            ."state, or location terms.\n\n"
            ."Problems:\n- ".implode("\n- ", $problems)."\n\n"
            ."Seed keywords:\n- ".implode("\n- ", $seedKeywords)."\n\n"
            .'Respond with ONLY JSON of the form '
            .'{"themes":[{"name":"...","terms":["..."],"problems":["..."],"keywords":["..."]}]}.';
    }

    private function system(): string
    {
        return 'You are an SEO content architect. You return strict JSON only, never prose. Themes must be geo-neutral and advisory.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parse(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        if (! is_array($decoded) || ! isset($decoded['themes']) || ! is_array($decoded['themes'])) {
            return [];
        }

        return array_values(array_filter($decoded['themes'], 'is_array'));
    }
}
