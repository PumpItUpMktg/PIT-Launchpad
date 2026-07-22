<?php

namespace App\Jobs;

use App\Gathering\ServiceEnricher;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bulk "Enrich all thin services" off the web request: fill the empty enrichment fields (symptoms /
 * what's-included / process / cost) of every THIN service on a site with generic trade knowledge as
 * SEEDED values — the same {@see ServiceEnricher} the per-service "AI fill" uses, so manual entry and
 * prices/guarantees are never touched. One Claude call per thin service, so it runs on the worker
 * (like GeneratePage) rather than blocking FPM across many calls. Best-effort per service: a single
 * AI failure is logged and skipped, never aborting the batch. Filled fields need operator review
 * (the "Enrich" modal); this only drafts them.
 */
class EnrichThinServices implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $siteId) {}

    public function handle(ServiceEnricher $enricher): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $services = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->filter(fn (Service $service): bool => $service->isThin());

        $enriched = 0;
        $failed = 0;

        foreach ($services as $service) {
            try {
                $filled = $enricher->enrich($site, $service);
                is_array($filled) && $filled !== [] ? $enriched++ : $failed++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Bulk enrich: a service failed', [
                    'site_id' => $site->id, 'service_id' => $service->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Bulk enrich complete', [
            'site_id' => $site->id, 'thin' => $services->count(), 'enriched' => $enriched, 'failed' => $failed,
        ]);
    }
}
