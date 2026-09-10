<?php

namespace App\Publishing\Seo;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Blocks\LocationSubject;
use App\Publishing\MetaBlobAssembler;
use App\Support\TownName;

/**
 * READ-ONLY census for the deterministic location-title backfill. Per published location page it computes
 * the AUTHORITATIVE town (LocationSubject) and compares it to the town named in the STORED title, so the
 * wrong-town / no-town count is a COMPUTED fact, not an estimate. It also reports the anchored/un-anchored
 * split, the composed <title> each page renders (MetaBlobAssembler::documentTitle — the real value the
 * <title> ships, deterministic for anchored pages), whether that exceeds the display limit, and how many
 * drafted H1s (hero_headline) do not contain the authoritative town (LLM prose — surfaced, never rewritten).
 *
 * documentTitle() reflects the CURRENT code (deterministic once this ships), while the stored title is the
 * pre-backfill value — so a wrong-town row reads "the stored title said Allentown; the page now renders
 * 'Sump Pump Services in Neptune, NJ'". `--execute` on the command cleans the stored value to match.
 */
final class LocationTitleReport
{
    /** The practical <title> display limit (mirrors SeoTitle::MAX_LENGTH). */
    public const LIMIT = 60;

    public function __construct(
        private readonly LocationSubject $subject,
        private readonly MetaBlobAssembler $assembler,
    ) {}

    /**
     * The whole portfolio, one entry per site that has at least one published location page, brand-first.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $out = [];
        foreach (Site::withoutGlobalScopes()->orderBy('brand_name')->get() as $site) {
            $entry = $this->forSite($site);
            if ($entry['total'] > 0) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * One site's location-title census. `pages` is ordered wrong-town first, then longest, so the
     * corrections lead.
     *
     * @return array{site: Site, brand: string, pages: list<array{slug: string, anchored: bool, stored_town: string, auth_town: string, status: string, title: string, len: int, over: bool, h1_mismatch: bool}>, total: int, anchored: int, unanchored: int, wrong_town: int, missing_town: int, over: int, h1_mismatch: int}
     */
    public function forSite(Site $site): array
    {
        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['site_id', 'slug', 'title', 'meta', 'slot_payload', 'page_type', 'source_name', 'geo_id', 'location_id', 'parent_location_id', 'primary_service_id']);

        $pages = [];
        $anchoredCount = 0;
        $wrongTown = 0;
        $missingTown = 0;
        $over = 0;
        $h1Mismatch = 0;

        foreach ($rows as $content) {
            ['city' => $authCity, 'anchored' => $anchored] = $this->subject->resolve($content);
            $storedTown = $this->townInTitle($this->storedTitle($content));

            $status = $this->classify($anchored, $authCity, $storedTown);

            $title = $this->assembler->documentTitle($content);
            $len = mb_strlen($title);
            $isOver = $len > self::LIMIT;

            $h1 = $this->heroHeadline($content);
            $h1Miss = $anchored && $authCity !== '' && $h1 !== '' && mb_stripos($h1, $authCity) === false;

            $anchoredCount += $anchored ? 1 : 0;
            $wrongTown += $status === 'wrong_town' ? 1 : 0;
            $missingTown += $status === 'missing_town' ? 1 : 0;
            $over += $isOver ? 1 : 0;
            $h1Mismatch += $h1Miss ? 1 : 0;

            $pages[] = [
                'slug' => (string) $content->slug,
                'anchored' => $anchored,
                'stored_town' => $storedTown,
                'auth_town' => $authCity,
                'status' => $status,
                'title' => $title,
                'len' => $len,
                'over' => $isOver,
                'h1_mismatch' => $h1Miss,
            ];
        }

        usort($pages, function (array $a, array $b): int {
            $rank = fn (array $p): int => $p['status'] === 'wrong_town' ? 2 : ($p['status'] === 'missing_town' ? 1 : 0);

            return [$rank($b), $b['len']] <=> [$rank($a), $a['len']];
        });

        return [
            'site' => $site,
            'brand' => trim((string) $site->brand_name),
            'pages' => $pages,
            'total' => count($pages),
            'anchored' => $anchoredCount,
            'unanchored' => count($pages) - $anchoredCount,
            'wrong_town' => $wrongTown,
            'missing_town' => $missingTown,
            'over' => $over,
            'h1_mismatch' => $h1Mismatch,
        ];
    }

    /**
     * Classify a page by how its stored title names the town vs. the authoritative subject:
     * `unanchored` (no authoritative subject — not the backfill's to touch), `wrong_town` (names a
     * different town), `missing_town` (names no town at all), else `correct`.
     */
    private function classify(bool $anchored, string $authCity, string $storedTown): string
    {
        if (! $anchored || $authCity === '') {
            return 'unanchored';
        }
        if ($storedTown === '') {
            return 'missing_town';
        }

        return TownName::key($storedTown) === TownName::key($authCity) ? 'correct' : 'wrong_town';
    }

    private function storedTitle(Content $content): string
    {
        $meta = is_array($content->meta) ? $content->meta : [];
        $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];

        return trim((string) ($seo['title'] ?? $content->title));
    }

    /** The town an "… in {Town}, {ST}" title names ('' when the title carries no such clause). */
    private function townInTitle(string $title): string
    {
        if (preg_match('/\bin\s+(.+?),\s*[A-Za-z]{2}\b/i', $title, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }

    private function heroHeadline(Content $content): string
    {
        $slots = is_array($content->slot_payload) ? $content->slot_payload : [];
        $raw = $slots['hero_headline'] ?? '';

        return is_array($raw) ? trim((string) ($raw[0] ?? '')) : trim((string) $raw);
    }
}
