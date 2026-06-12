<?php

namespace App\Operator;

use App\Enums\ContentStatus;
use App\Enums\RenderStatus;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The pipeline dashboard aggregations, computed for the whole portfolio
 * (siteId = null) or a single tenant. Pure read queries over §1–§6 + §2 state;
 * the Filament widgets render what these return.
 */
class PipelineMetrics
{
    /**
     * Stat cards: the headline counts.
     *
     * @return array<string, int>
     */
    public function statCards(?string $siteId = null): array
    {
        return [
            'needs_review' => $this->countStatus($siteId, ContentStatus::NeedsReview),
            'approved_pending' => $this->countStatus($siteId, ContentStatus::Approved),
            'published_this_week' => $this->contents($siteId)
                ->where('status', ContentStatus::Published->value)
                ->where('published_at', '>=', now()->startOfWeek())
                ->count(),
            'render_failed' => $this->countStatus($siteId, ContentStatus::RenderFailed),
            'publish_failed' => $this->countStatus($siteId, ContentStatus::PublishFailed),
            'candidates' => $this->countStatus($siteId, ContentStatus::Candidate),
        ];
    }

    /**
     * The pipeline funnel: how many sit at each stage (candidate → published).
     *
     * @return array<string, int>
     */
    public function funnel(?string $siteId = null): array
    {
        $counts = $this->statusCounts($siteId);
        $at = fn (ContentStatus ...$statuses): int => array_sum(array_map(
            fn (ContentStatus $s) => (int) ($counts[$s->value] ?? 0),
            $statuses,
        ));

        return [
            'candidate' => $at(ContentStatus::Candidate),
            'scored' => $at(ContentStatus::Scored),
            'drafted' => $at(ContentStatus::Drafted),
            'in_review' => $at(ContentStatus::NeedsReview, ContentStatus::InReview),
            'approved' => $at(ContentStatus::Approved, ContentStatus::Rendering, ContentStatus::Publishing),
            'published' => $at(ContentStatus::Published),
        ];
    }

    /**
     * Content volume per silo — which silos produce, which are starved.
     *
     * @return list<array{silo_id: string, silo_name: string, total: int}>
     */
    public function perSilo(?string $siteId = null): array
    {
        $counts = $this->contents($siteId)
            ->whereNotNull('silo_id')
            ->selectRaw('silo_id, count(*) as aggregate')
            ->groupBy('silo_id')
            ->pluck('aggregate', 'silo_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $names = Silo::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $counts->keys())
            ->pluck('name', 'id');

        $rows = [];
        foreach ($counts as $siloId => $total) {
            $rows[] = [
                'silo_id' => (string) $siloId,
                'silo_name' => (string) ($names[$siloId] ?? $siloId),
                'total' => (int) $total,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return $rows;
    }

    /**
     * Publishing cadence over the last N weeks (oldest → newest), zero-filled.
     *
     * @return array<string, int> keyed by ISO week label (e.g. "2026-W23")
     */
    public function publishedPerWeek(?string $siteId = null, int $weeks = 8): array
    {
        $since = now()->startOfWeek()->subWeeks($weeks - 1);

        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $week = (clone $since)->addWeeks($i);
            $buckets[$this->weekLabel($week)] = 0;
        }

        $this->contents($siteId)
            ->where('status', ContentStatus::Published->value)
            ->where('published_at', '>=', $since)
            ->pluck('published_at')
            ->each(function (?Carbon $publishedAt) use (&$buckets): void {
                if ($publishedAt === null) {
                    return;
                }
                $label = $this->weekLabel($publishedAt);
                if (array_key_exists($label, $buckets)) {
                    $buckets[$label]++;
                }
            });

        return $buckets;
    }

    /**
     * App-level job health: render success/failure and publish failures, plus
     * the failed content (for linking + the §2 reset action).
     *
     * @return array{render_succeeded: int, render_failed: int, publish_failed: int, failed_content: list<array{id: string, title: string, status: string}>}
     */
    public function jobHealth(?string $siteId = null): array
    {
        $renderQuery = fn (): Builder => RenderJob::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn (Builder $q) => $q->where('site_id', $siteId));

        $failedContent = $this->contents($siteId)
            ->whereIn('status', [ContentStatus::RenderFailed->value, ContentStatus::PublishFailed->value])
            ->get(['id', 'title', 'status'])
            ->map(fn (Content $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'status' => $c->status->value,
            ])
            ->all();

        return [
            'render_succeeded' => (clone $renderQuery())->where('status', RenderStatus::Succeeded->value)->count(),
            'render_failed' => (clone $renderQuery())->where('status', RenderStatus::RenderFailed->value)->count(),
            'publish_failed' => $this->countStatus($siteId, ContentStatus::PublishFailed),
            'failed_content' => $failedContent,
        ];
    }

    /**
     * @return Builder<Content>
     */
    private function contents(?string $siteId): Builder
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn (Builder $q) => $q->where('site_id', $siteId));
    }

    private function countStatus(?string $siteId, ContentStatus $status): int
    {
        return $this->contents($siteId)->where('status', $status->value)->count();
    }

    /**
     * @return Collection<string, int>
     */
    private function statusCounts(?string $siteId): Collection
    {
        return $this->contents($siteId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
    }

    private function weekLabel(Carbon $date): string
    {
        return $date->isoFormat('GGGG-[W]WW');
    }
}
