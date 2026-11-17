<?php

namespace App\Publishing\Seo;

use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Models\Content;
use App\Models\GscUrlQueryDaily;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;

/**
 * Which of the tenant's services an office page is actually searched for (§ Office pages, step 4): the
 * Search Console queries the office HUB page showed for over the trailing window, each credited to the
 * most specific service whose name the query contains, summed by impressions. The title then leads with
 * the service people type ("sump pump repair") instead of the catalog's generic head term ("service").
 *
 * A TOWN page inherits its parent hub's demand — town pages are too small to measure on their own and
 * the searcher's intent is the same. First-party data only, no vendor call; nothing below the floor
 * counts, so a page with no real demand falls back to the pillar service as before.
 */
final class ServiceDemand
{
    /** Words that carry no service meaning in a name or a query. */
    private const STOP = ['service', 'services', 'and', '&', 'the', 'a', 'an', 'for', 'of', 'local', 'company', 'companies', 'contractor', 'contractors', 'near', 'me'];

    public function __construct(
        private readonly int $windowDays = 90,
        private readonly int $floorImpressions = 20,
    ) {}

    /**
     * The office's lead services by search demand, most searched first — the specific (non-pillar)
     * services only, since the pillar is the generic the title falls back to anyway.
     *
     * @return list<string> service names
     */
    public function leadServices(Content $content, int $max = 2): array
    {
        $url = $this->hubUrl($content);
        if ($url === null) {
            return [];
        }
        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->where('silo_role', '!=', ServiceSiloRole::Pillar->value)
            ->get(['id', 'name'])
            ->map(fn (Service $s): array => ['name' => trim((string) $s->name), 'tokens' => self::tokens((string) $s->name)])
            ->filter(fn (array $s): bool => $s['name'] !== '' && $s['tokens'] !== [])
            ->sortByDesc(fn (array $s): int => count($s['tokens']))   // most specific first: it claims the query
            ->values()->all();
        if ($services === []) {
            return [];
        }

        $rows = GscUrlQueryDaily::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $content->site_id)
            ->whereIn('url', [$url, rtrim($url, '/')])
            ->where('date', '>=', Carbon::now()->subDays($this->windowDays)->toDateString())
            ->selectRaw('query, sum(impressions) as impressions')
            ->groupBy('query')
            ->get();

        $demand = [];
        foreach ($rows as $row) {
            $queryTokens = self::tokens((string) $row->query);
            foreach ($services as $service) {
                if (array_diff($service['tokens'], $queryTokens) === []) {
                    $demand[$service['name']] = ($demand[$service['name']] ?? 0) + (int) $row->impressions;
                    break;   // the most specific matching service takes the query
                }
            }
        }
        $demand = array_filter($demand, fn (int $n): bool => $n >= $this->floorImpressions);
        arsort($demand);

        return array_slice(array_keys($demand), 0, max(1, $max));
    }

    /**
     * Two services as one title term, the shared leading words said once: "Sump Pump Repair" + "Sump Pump
     * Installation" → "Sump Pump Repair & Installation". Unrelated names simply join with "&".
     */
    public static function join(string $a, string $b): string
    {
        $wa = preg_split('/\s+/', trim($a)) ?: [];
        $wb = preg_split('/\s+/', trim($b)) ?: [];
        $lower = fn (array $w): array => array_map('mb_strtolower', $w);
        if (array_diff($lower($wb), $lower($wa)) === []) {
            return $a;   // the second adds no word the first lacks ("Sump Pump Repair" + "Sump Pump")
        }
        $common = 0;
        while ($common < count($wa) - 1 && $common < count($wb) - 1 && mb_strtolower($wa[$common]) === mb_strtolower($wb[$common])) {
            $common++;
        }
        $rest = implode(' ', array_slice($wb, $common));

        return $common > 0 && $rest !== '' ? $a.' & '.$rest : $a.' & '.$b;
    }

    /** The office hub page's public URL: the page itself for a hub, the parent's hub for a town. */
    private function hubUrl(Content $content): ?string
    {
        if ($content->page_type !== PageType::Location) {
            return null;
        }
        $hub = $content;
        if ($content->location_id === null && $content->parent_location_id !== null) {
            $hub = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $content->site_id)
                ->where('page_type', PageType::Location->value)
                ->where('location_id', $content->parent_location_id)
                ->first();
            if ($hub === null) {
                return null;
            }
        }
        $domain = Site::withoutGlobalScope(SiteScope::class)->whereKey($content->site_id)->value('domain_url');

        return PublicUrl::forContent(is_string($domain) ? $domain : null, $hub);
    }

    /** @return list<string> lowercase meaning-bearing words, in order */
    private static function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [];

        return array_values(array_filter($words, fn (string $w): bool => $w !== '' && ! in_array($w, self::STOP, true)));
    }
}
