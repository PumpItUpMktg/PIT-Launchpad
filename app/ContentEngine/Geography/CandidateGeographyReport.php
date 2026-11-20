<?php

namespace App\ContentEngine\Geography;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Enums\PlaceVerdict;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * READ-ONLY: the current reactive candidate backlog scored against the tenant's footprint — how many are in
 * footprint / region / out of market / placeless, and which. A row the funnel already stamped
 * (`meta.place_scope`) is read as stamped; an older row gets one narrow place extraction. Nothing is written.
 */
class CandidateGeographyReport
{
    public function __construct(private readonly PlaceExtractor $extractor) {}

    /**
     * @return array{
     *   footprint: Footprint,
     *   counts: array<string, int>,
     *   extracted: int,
     *   rows: list<array{id: string, title: string, source: ?string, status: string, place: ?string, verdict: PlaceVerdict, stamped: bool, created_at: ?string}>
     * }
     */
    public function report(Site $site, int $limit = 200): array
    {
        $footprint = Footprint::forSite($site);

        $candidates = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('intake_type', IntakeType::Reactive->value)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::InReview->value])
            ->orderByDesc('created_at')
            ->limit(max(1, $limit))
            ->get(['id', 'title', 'source_name', 'status', 'meta', 'created_at']);

        $rows = [];
        $counts = array_fill_keys(array_map(fn (PlaceVerdict $v): string => $v->value, PlaceVerdict::cases()), 0);
        $extracted = 0;
        foreach ($candidates as $c) {
            $meta = is_array($c->meta) ? $c->meta : [];
            $stamped = isset($meta['place_scope']) && is_string($meta['place_scope']) && PlaceVerdict::tryFrom($meta['place_scope']) !== null;
            if ($stamped) {
                $verdict = PlaceVerdict::from((string) $meta['place_scope']);
                $place = isset($meta['story_place']) && is_string($meta['story_place']) ? $meta['story_place'] : null;
            } else {
                $found = $this->extractor->extract((string) $c->title, (string) ($c->source_name ?? ''));
                $extracted++;
                $place = $found['place'];
                $verdict = $footprint->classify($found['state']);
            }
            $counts[$verdict->value]++;
            $rows[] = [
                'id' => (string) $c->id,
                'title' => (string) $c->title,
                'source' => $c->source_name,
                'status' => $c->status->value,
                'place' => $place,
                'verdict' => $verdict,
                'stamped' => $stamped,
                'created_at' => $c->created_at?->toDateString(),
            ];
        }

        return ['footprint' => $footprint, 'counts' => $counts, 'extracted' => $extracted, 'rows' => $rows];
    }
}
