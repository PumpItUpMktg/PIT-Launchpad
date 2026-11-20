<?php

namespace App\ContentEngine\Coverage;

use App\Models\Content;
use App\Models\Site;
use App\Support\PublicUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A live post's Search Console standing over a trailing window — impressions, clicks and the
 * impression-weighted position from the per-URL daily store the `launchpad:sync-gsc` job already fills.
 * The URL is matched by path (scheme/host/trailing slash dropped) so a domain change never loses history.
 */
class PostStanding
{
    /** @return array{impressions: int, clicks: int, position: ?float, url: ?string} */
    public function for(Site $site, Content $post, int $days = 90): array
    {
        $url = PublicUrl::forContent($site->domain_url, $post);
        if ($url === null) {
            return ['impressions' => 0, 'clicks' => 0, 'position' => null, 'url' => null];
        }
        $path = self::normalizePath((string) parse_url($url, PHP_URL_PATH));

        $rows = DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->where('date', '>=', Carbon::now()->subDays(max(1, $days))->toDateString())
            ->selectRaw('url,
                SUM(impressions) AS impr,
                SUM(clicks) AS clicks,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE impressions END) AS impr_pos,
                SUM(CASE WHEN position IS NULL THEN 0 ELSE position * impressions END) AS posw')
            ->groupBy('url')
            ->get();

        $impr = 0;
        $clicks = 0;
        $imprPos = 0;
        $posw = 0.0;
        foreach ($rows as $row) {
            if (self::normalizePath((string) parse_url((string) $row->url, PHP_URL_PATH)) !== $path) {
                continue;
            }
            $impr += (int) $row->impr;
            $clicks += (int) $row->clicks;
            $imprPos += (int) $row->impr_pos;
            $posw += (float) $row->posw;
        }

        return [
            'impressions' => $impr,
            'clicks' => $clicks,
            'position' => $imprPos > 0 ? round($posw / $imprPos, 1) : null,
            'url' => $url,
        ];
    }

    public static function normalizePath(?string $path): string
    {
        $p = '/'.trim((string) $path, '/');

        return $p === '/' ? '/' : rtrim($p, '/');
    }
}
