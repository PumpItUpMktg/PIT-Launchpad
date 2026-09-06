<?php

namespace App\ContentEngine\BlogQueue;

use App\Enums\CandidateScope;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Enums\ShelfLife;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * MANUAL blog intake — the hand-typed idea ("we should write about the Polk high-water-table job"). Unlike
 * the DIRECTED lane ({@see DirectedIntake}), which materializes a keyword-bound BlogTarget, this needs no
 * keyword: the operator types a title/angle, picks a silo, and optionally a town for local scope. It creates
 * a kind=post Content candidate straight onto the Candidates board, `source_name='manual'`.
 *
 * Classified on the same two axes as everything else (PR 4): shelf_life=topical by default (no selector —
 * fast capture) and scope=local when a town is given, else general. Because it is topical with no article
 * date, the daily expiry sweep (`launchpad:expire-candidates`) ages it by created_at at 30 days like any
 * candidate — an idea nobody wrote in a month falls off. The board shows it cap-exempt
 * (an operator's explicit ask is never hidden behind a silo's ingestion backlog).
 */
class ManualCandidateIntake
{
    /** Create a manual candidate. Returns null when the title is empty or the silo isn't this site's. */
    public function create(Site $site, string $title, string $siloId, ?string $town = null): ?Content
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        $silo = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereKey($siloId)->first();
        if ($silo === null) {
            return null; // never file a candidate under another tenant's (or a nonexistent) silo
        }

        $town = $town !== null ? trim($town) : '';
        $scope = $town !== '' ? CandidateScope::Local : CandidateScope::General;

        $meta = [
            'shelf_life' => ShelfLife::Topical->value, // topical by default → expiry-subject (aged by created_at)
            'scope' => $scope->value,
            'manual' => true,                          // cap-exempt marker for the board grouping
        ];
        if ($scope === CandidateScope::Local) {
            $meta['manual_town'] = $town;              // the local anchor, for the drafter's local injection
        }

        return Content::create([
            'site_id' => $site->id,
            'silo_id' => $siloId,
            'matched_silo_id' => $siloId,
            'kind' => ContentKind::Post,
            'intake_type' => IntakeType::Directed, // operator-initiated (not news-reactive)
            'status' => ContentStatus::Candidate,
            'source_name' => 'manual',
            'title' => Str::ucfirst($title),
            'slug' => $this->uniqueSlug($site->id, $title),
            'version' => 1,
            'meta' => $meta,
        ]);
    }

    private function uniqueSlug(string $siteId, string $title): string
    {
        $base = Str::slug($title) ?: 'manual-post';
        $slug = $base;
        $suffix = 1;

        while (Content::withoutGlobalScope(SiteScope::class)
            ->withTrashed()
            ->where('site_id', $siteId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
