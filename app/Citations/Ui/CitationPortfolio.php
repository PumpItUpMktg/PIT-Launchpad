<?php

namespace App\Citations\Ui;

use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Models\CitationScanRun;
use App\Models\CitationStatus;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;

/**
 * View-model for the citation portfolio index (§ Citations UI, PR D) — one {@see PortfolioRow} per tenant, the
 * exceptions-only cross-tenant view. Median coverage is a median ACROSS the tenant's listings (never a single
 * rolled-up figure — the tenant page keeps coverage per listing); the mismatch / submitted / stalled counts
 * are the attention drivers. Rows come back most-urgent-first: stalled desc, then mismatch desc.
 */
final class CitationPortfolio
{
    public function __construct(private readonly TenantCitationBoard $board = new TenantCitationBoard) {}

    /**
     * @return list<PortfolioRow>
     */
    public function rows(): array
    {
        $rows = [];
        foreach (Site::query()->orderBy('brand_name')->get() as $site) {
            CurrentSite::set((string) $site->id);
            $cards = $this->board->forSite($site);
            if ($cards === []) {
                continue;
            }

            $listingCount = count(array_filter($cards, fn (LocationCitationCard $c): bool => $c->hasGbp));
            $coverages = array_values(array_filter(
                array_map(fn (LocationCitationCard $c): ?int => $c->coveragePercent, $cards),
                fn (?int $v): bool => $v !== null,
            ));

            $rows[] = new PortfolioRow(
                siteId: (string) $site->id,
                tenantName: (string) ($site->brand_name ?: 'Untitled'),
                listingCount: $listingCount,
                medianCoverage: $this->median($coverages),
                mismatchCount: $this->countPresence($site->id, CitationPresence::PresentMismatch),
                submittedCount: $this->countLifecycle($site->id, CitationLifecycleState::Submitted),
                stalledCount: $this->countLifecycle($site->id, CitationLifecycleState::Stalled),
                lastScanAt: $this->lastScanAt($site->id),
            );
        }

        usort($rows, fn (PortfolioRow $a, PortfolioRow $b): int => [$b->stalledCount, $b->mismatchCount] <=> [$a->stalledCount, $a->mismatchCount]);

        return $rows;
    }

    /** @param  list<int>  $values */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$mid]
            : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    private function countPresence(string $siteId, CitationPresence $presence): int
    {
        return CitationStatus::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('presence', $presence->value)->count();
    }

    private function countLifecycle(string $siteId, CitationLifecycleState $lifecycle): int
    {
        return CitationStatus::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('lifecycle', $lifecycle->value)->count();
    }

    private function lastScanAt(string $siteId): ?Carbon
    {
        return CitationScanRun::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->latest('started_at')->first()?->started_at;
    }
}
