<?php

namespace App\Publishing\Seo;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\RenderStatus;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Blocks\LocationSubject;
use App\Publishing\TenantStorage;
use App\Support\TownName;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * READ-ONLY census + deterministic rename plan for rendered image FILENAMES that carry the WRONG town on a
 * location page — the last "Allentown" left on a Neptune page after the alt/title/caption rewrite: the
 * R2 key "sites/{site}/sump-pump-repair-allentown-pa.jpg" was minted from the drafter's seo_filename under
 * the old grounding, and it reaches the page four times (img src, srcset, og:image, schema ImageObject.url).
 * The bytes are fine (a generic technician-in-a-basement render); only the name is wrong, so the fix is a
 * same-bucket COPY to the town-correct key + repointing the render job — no fal or vision spend.
 *
 * A "{town}-{st}" token run in a slug is a place only when the town is a KNOWN place of the site (its
 * coverage areas, GBP locations, and served towns) — unlike prose, a filename has no capitalization to tell
 * "allentown-pa" from "service-pa", so the known-place set is the guard. The page's own town, the brand, a
 * bare state, and a state list ("nj-pa-md") are never a town. Only ANCHORED pages are assessed.
 */
final class ImageFilenameTownReport
{
    /** Lower-case US state abbreviations — a "{x}-{st}" token run is a place only when st is one of these. */
    private const STATE_ABBREVS = [
        'al', 'ak', 'az', 'ar', 'ca', 'co', 'ct', 'de', 'fl', 'ga', 'hi', 'id', 'il', 'in', 'ia', 'ks', 'ky',
        'la', 'me', 'md', 'ma', 'mi', 'mn', 'ms', 'mo', 'mt', 'ne', 'nv', 'nh', 'nj', 'nm', 'ny', 'nc', 'nd',
        'oh', 'ok', 'or', 'pa', 'ri', 'sc', 'sd', 'tn', 'tx', 'ut', 'vt', 'va', 'wa', 'wv', 'wi', 'wy', 'dc',
    ];

    public function __construct(private readonly LocationSubject $subject) {}

    /**
     * One site's census: every succeeded render job on an anchored, published location page whose R2 key
     * (or a responsive variant's) names a foreign known town, with the exact rename. `proposed` keys are
     * already collision-free on the bucket (a "-2" suffix when the target exists).
     *
     * @return array{site: Site, brand: string, rows: list<array{content_id: string, slug: string, auth: string, job_id: string, slot: string, current: string, proposed: string, variants: array<string, array{0: string, 1: string}>}>, pages: int, anchored: int, unanchored: int, jobs: int, known_places: int, affected_pages: int}
     */
    public function forSite(Site $site): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'slug', 'title', 'geo_id', 'location_id', 'parent_location_id']);

        $known = $this->knownPlaces($site);
        $brandSlug = Str::slug((string) $site->brand_name);
        $disk = Storage::disk(TenantStorage::DISK);

        $rows = [];
        $anchored = 0;
        $jobsScanned = 0;
        $affected = [];

        foreach ($pages as $content) {
            ['city' => $city, 'state' => $state, 'anchored' => $isAnchored] = $this->subject->resolve($content);
            if (! $isAnchored || $city === '') {
                continue; // no authoritative town to rename to
            }
            $anchored++;
            $authSlug = Str::slug($city);
            $replacementSlug = Str::slug($state !== '' ? $city.' '.$state : $city);
            $auth = $state !== '' ? $city.', '.$state : $city;

            $jobs = RenderJob::withoutGlobalScope(SiteScope::class)
                ->where('content_id', $content->id)
                ->where('status', RenderStatus::Succeeded->value)
                ->whereNotNull('slot')
                ->whereNotNull('r2_key')
                ->get(['id', 'slot', 'r2_key', 'variants']);

            foreach ($jobs as $job) {
                $jobsScanned++;
                $current = (string) $job->r2_key;
                $proposed = $this->renameKey($current, $known, $authSlug, $replacementSlug, $brandSlug);

                $variants = [];
                $variantMap = is_array($job->variants) ? $job->variants : [];
                foreach ($variantMap as $width => $key) {
                    $key = (string) $key;
                    $new = $this->renameKey($key, $known, $authSlug, $replacementSlug, $brandSlug);
                    if ($new !== $key) {
                        $variants[(string) $width] = [$key, $this->freeKey($disk, $new, $key)];
                    }
                }

                if ($proposed === $current && $variants === []) {
                    continue;
                }
                $rows[] = [
                    'content_id' => (string) $content->id,
                    'slug' => (string) $content->slug,
                    'auth' => $auth,
                    'job_id' => (string) $job->id,
                    'slot' => (string) $job->slot,
                    'current' => $current,
                    'proposed' => $proposed === $current ? $current : $this->freeKey($disk, $proposed, $current),
                    'variants' => $variants,
                ];
                $affected[(string) $content->id] = true;
            }
        }

        return [
            'site' => $site,
            'brand' => trim((string) $site->brand_name),
            'rows' => $rows,
            'pages' => count($pages),
            'anchored' => $anchored,
            'unanchored' => count($pages) - $anchored,
            'jobs' => $jobsScanned,
            'known_places' => count($known),
            'affected_pages' => count($affected),
        ];
    }

    /**
     * The R2 key with its basename rewritten ({@see rewrite}); the tenant prefix is kept as-is.
     *
     * @param  array<string, true>  $known
     */
    public function renameKey(string $key, array $known, string $authSlug, string $replacementSlug, string $brandSlug): string
    {
        $basename = basename($key);
        $renamed = $this->rewrite($basename, $known, $authSlug, $replacementSlug, $brandSlug);
        if ($renamed === $basename) {
            return $key;
        }
        $dir = dirname($key);

        return ($dir === '.' || $dir === '') ? $renamed : $dir.'/'.$renamed;
    }

    /**
     * Swap every FOREIGN known-place "{town}-{st}" token run in a slug filename for "{auth-town}-{st}";
     * returns the filename unchanged when nothing foreign is named. Longest town (up to three tokens) wins
     * so "lower-macungie-pa" is one place, not "macungie-pa" with a stray "lower".
     *
     * @param  array<string, true>  $known  known-place slugs of the site
     */
    public function rewrite(string $basename, array $known, string $authSlug, string $replacementSlug, string $brandSlug): string
    {
        $ext = pathinfo($basename, PATHINFO_EXTENSION);
        $stem = $ext !== '' ? substr($basename, 0, -(strlen($ext) + 1)) : $basename;
        $tokens = explode('-', $stem);
        $count = count($tokens);

        $out = [];
        $i = 0;
        while ($i < $count) {
            $hit = null;
            $own = null;
            for ($len = 3; $len >= 1; $len--) {
                $stateAt = $i + $len;
                if ($stateAt >= $count || ! in_array($tokens[$stateAt], self::STATE_ABBREVS, true)) {
                    continue;
                }
                if (isset($tokens[$stateAt + 1]) && in_array($tokens[$stateAt + 1], self::STATE_ABBREVS, true)) {
                    continue; // "nj-pa-md" — a state list, the words before it are prose
                }
                $candidate = implode('-', array_slice($tokens, $i, $len));
                if (! isset($known[$candidate])) {
                    continue; // not a place the site knows ("service-pa")
                }
                if ($candidate === $authSlug || str_ends_with($candidate, '-'.$authSlug)) {
                    $own = $len; // the page's own town — keep the WHOLE run, so its tail ("plainfield-nj"
                    break;       // inside "north-plainfield-nj") is never re-matched as a foreign place
                }
                if ($brandSlug !== '' && (str_contains($brandSlug, $candidate) || str_contains($candidate, $brandSlug))) {
                    continue;
                }
                $hit = $len;
                break;
            }

            if ($own !== null) {
                for ($k = $i; $k <= $i + $own; $k++) {
                    $out[] = $tokens[$k];
                }
                $i += $own + 1;

                continue;
            }
            if ($hit === null) {
                $out[] = $tokens[$i];
                $i++;

                continue;
            }
            $out[] = $replacementSlug;
            $i += $hit + 1; // the town tokens + the state token
        }

        $renamed = implode('-', $out);

        return $ext !== '' ? $renamed.'.'.$ext : $renamed;
    }

    /**
     * Every place the site knows, as slugs: coverage-area names, GBP location cities, and served towns.
     *
     * @return array<string, true>
     */
    private function knownPlaces(Site $site): array
    {
        $known = [];
        $add = function (string $name) use (&$known): void {
            $slug = Str::slug(TownName::display($name));
            if ($slug !== '') {
                $known[$slug] = true;
            }
        };

        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('name') as $name) {
            $add((string) $name);
        }
        foreach (Location::withoutGlobalScopes()->where('site_id', $site->id)->get() as $location) {
            $add($location->cityState()['city']);
            $servedTowns = $location->getAttribute('served_towns');
            foreach (is_array($servedTowns) ? $servedTowns : [] as $town) {
                if (is_array($town) && isset($town['name'])) {
                    $add((string) $town['name']);
                }
            }
        }

        return $known;
    }

    /**
     * A key that is free on the bucket: the proposed key itself, or "-2", "-3", … before the extension when
     * an object already sits there (never overwrite another image). The key being renamed FROM never counts
     * as taken.
     */
    private function freeKey(Filesystem $disk, string $proposed, string $from): string
    {
        if ($proposed === $from || ! $disk->exists($proposed)) {
            return $proposed;
        }
        $ext = pathinfo($proposed, PATHINFO_EXTENSION);
        $stem = $ext !== '' ? substr($proposed, 0, -(strlen($ext) + 1)) : $proposed;
        for ($n = 2; $n < 100; $n++) {
            $candidate = $ext !== '' ? "{$stem}-{$n}.{$ext}" : "{$stem}-{$n}";
            if (! $disk->exists($candidate)) {
                return $candidate;
            }
        }

        return $proposed;
    }
}
