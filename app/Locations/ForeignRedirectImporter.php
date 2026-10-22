<?php

namespace App\Locations;

use App\Enums\RedirectSource;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Fold the redirect rules another plugin on the live site holds (301 Redirects Pro, Redirection, …) into the
 * control-plane {@see Redirect} store, so that plugin can be switched off without losing a rule. The companion
 * reads the rules straight from the plugin's database tables (no plugin UI involved); this plans the import
 * against what we already hold and the pages that are live:
 *
 *   - covered   — we already send that path to the same place; nothing to do;
 *   - new       — a rule only the plugin knows; written on apply;
 *   - conflict  — the plugin sends a path somewhere OTHER than our rule does; never auto-resolved, listed
 *                 for a hand decision (launchpad:fix-redirect);
 *   - live      — the path is a live page of ours; a redirect would shadow it, so it is skipped;
 *   - unparsed  — a pattern rule (regex / wildcard) or a row with no usable from/to; listed, not imported.
 *
 * `plan()` touches nothing; `apply()` writes the `new` rows in one transaction. The push is the caller's.
 */
class ForeignRedirectImporter
{
    public function __construct(private readonly WordpressClientFactory $factory) {}

    /**
     * @return array{
     *   new: list<array{from: string, to: string, code: int, source: string, hits: int|null}>,
     *   covered: list<array{from: string, to: string}>,
     *   conflict: list<array{from: string, to: string, ours: string, source: string}>,
     *   live: list<array{from: string, to: string}>,
     *   unparsed: list<array{from: string, to: string, source: string, why: string}>,
     *   tables: list<array<string, mixed>>,
     *   options: list<array<string, mixed>>,
     *   total: int
     * }
     */
    public function plan(Site $site): array
    {
        $foreign = $this->factory->forSite($site)->foreignRedirects();
        $host = strtolower((string) parse_url((string) $site->domain_url, PHP_URL_HOST));

        $liveSlugs = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereNotNull('slug')
            ->pluck('slug')->map(fn ($s): string => $this->path((string) $s))->filter()->unique()->flip()->all();
        $ours = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', 'active')->get()
            ->keyBy(fn (Redirect $r): string => $this->path((string) $r->from_url));

        $new = $covered = $conflict = $live = $unparsed = [];
        $seen = [];
        foreach ($foreign['rules'] as $rule) {
            $rawFrom = trim((string) ($rule['from'] ?? ''));
            $rawTo = trim((string) ($rule['to'] ?? ''));
            $source = (string) ($rule['source'] ?? 'unknown');
            $code = (int) ($rule['code'] ?? 301);
            $code = in_array($code, [301, 302, 307, 308, 410], true) ? $code : 301;
            $hits = isset($rule['hits']) && is_numeric($rule['hits']) ? (int) $rule['hits'] : null;

            if ($rawFrom === '' || preg_match('/[*^$()\[\]|\\\\]/', $rawFrom) === 1 || ! empty($rule['regex'])) {
                $unparsed[] = ['from' => $rawFrom, 'to' => $rawTo, 'source' => $source, 'why' => $rawFrom === '' ? 'no source path' : 'pattern rule'];

                continue;
            }
            $from = $this->path($rawFrom, $host);
            if ($from === '' || $from === null) {
                $unparsed[] = ['from' => $rawFrom, 'to' => $rawTo, 'source' => $source, 'why' => 'source is not a path on this site'];

                continue;
            }
            $to = $code === 410 ? '' : $this->target($rawTo, $host);
            if ($code !== 410 && ($to === '' || $to === $from)) {
                $unparsed[] = ['from' => $from, 'to' => $rawTo, 'source' => $source, 'why' => $to === '' ? 'no destination' : 'redirects to itself'];

                continue;
            }
            if (isset($seen[$from])) {
                continue; // the plugin held the same path twice; first wins
            }
            $seen[$from] = true;

            if (isset($liveSlugs[$from])) {
                $live[] = ['from' => $from, 'to' => $to];

                continue;
            }
            $mine = $ours->get($from);
            if ($mine !== null) {
                $mineTo = (int) $mine->code >= 400 ? '' : $this->target((string) $mine->to_url, $host);
                if ($mineTo === $to) {
                    $covered[] = ['from' => $from, 'to' => $to];
                } else {
                    $conflict[] = ['from' => $from, 'to' => $to, 'ours' => $mineTo !== '' ? $mineTo : '(gone)', 'source' => $source];
                }

                continue;
            }
            $new[] = ['from' => $from, 'to' => $to, 'code' => $code, 'source' => $source, 'hits' => $hits];
        }

        return [
            'new' => $new, 'covered' => $covered, 'conflict' => $conflict, 'live' => $live, 'unparsed' => $unparsed,
            'tables' => $foreign['tables'], 'options' => $foreign['options'], 'total' => count($foreign['rules']),
        ];
    }

    /**
     * Write the planned `new` rows. Returns how many were written.
     *
     * @param  array{new: list<array{from: string, to: string, code: int}>}  $plan
     */
    public function apply(Site $site, array $plan): int
    {
        $rows = $plan['new'];
        if ($rows === []) {
            return 0;
        }
        DB::transaction(function () use ($site, $rows): void {
            foreach ($rows as $row) {
                Redirect::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    ['site_id' => $site->id, 'from_url' => $row['from']],
                    ['to_url' => $row['to'], 'code' => $row['code'], 'status' => 'active', 'source' => RedirectSource::Migration->value],
                );
            }
        });

        return count($rows);
    }

    /**
     * A source rule as a leading-slash path on this site (trailing slash stripped, lowercased — the plugin's
     * key form). An absolute URL on another host is not ours: null.
     */
    private function path(string $value, string $host = ''): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            $h = strtolower((string) parse_url($value, PHP_URL_HOST));
            if ($host !== '' && $h !== '' && $h !== $host && $h !== 'www.'.$host && 'www.'.$h !== $host) {
                return null;
            }
        }
        $parsed = parse_url($value, PHP_URL_PATH);
        $path = is_string($parsed) ? $parsed : $value;
        $path = strtolower(trim($path, '/'));

        return $path === '' ? '' : '/'.$path;
    }

    /** A destination: a path on this site when it is ours, else the absolute URL as given. */
    private function target(string $value, string $host): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $path = $this->path($value, $host);
        if ($path === null) {
            return $value; // another host — keep the absolute URL
        }

        return $path === '' ? '/' : $path;
    }
}
