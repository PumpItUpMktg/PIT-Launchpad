<?php

namespace App\Client;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

/**
 * The body of work: content published and coverage across silos — what the
 * engine has produced for the client.
 */
class CoverageSummary
{
    public function publishedCount(Site $site): int
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->count();
    }

    /**
     * @return list<array{silo_id: string, silo_name: string, published: int}>
     */
    public function perSilo(Site $site): array
    {
        $counts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('silo_id')
            ->selectRaw('silo_id, count(*) as aggregate')
            ->groupBy('silo_id')
            ->pluck('aggregate', 'silo_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $names = Silo::withoutGlobalScope(SiteScope::class)->whereIn('id', $counts->keys())->pluck('name', 'id');

        $rows = [];
        foreach ($counts as $siloId => $aggregate) {
            $rows[] = [
                'silo_id' => (string) $siloId,
                'silo_name' => (string) ($names[$siloId] ?? $siloId),
                'published' => (int) $aggregate,
            ];
        }

        return $rows;
    }
}
