<?php

namespace App\Console\Commands;

use App\Enums\RenderStatus;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\ImageVariantGenerator;
use App\Publishing\TenantStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backfills responsive downscale variants for images that were rendered before the variant pipeline
 * shipped (their render_jobs.variants is null). It re-reads each source object from R2, derives the
 * smaller widths with GD, stores them beside the source, and records the { width => r2_key } map — no
 * fal call, so it costs nothing but the downscale. Idempotent: jobs that already carry variants are
 * skipped, so it is safe to re-run. The new variants reach a live page on its next publish/re-push
 * (the page HTML is baked at publish time); this command only makes the variants exist.
 */
class BackfillImageVariantsCommand extends Command
{
    protected $signature = 'launchpad:backfill-image-variants
        {--site= : Restrict to one Site id (default: every tenant)}';

    protected $description = 'Derive responsive srcset variants for already-rendered images that lack them.';

    public function handle(ImageVariantGenerator $generator, TenantStorage $storage): int
    {
        $query = RenderJob::withoutGlobalScope(SiteScope::class)
            ->where('status', RenderStatus::Succeeded->value)
            ->whereNotNull('r2_key')
            ->whereNull('variants');

        $siteId = $this->option('site');
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $disk = Storage::disk(TenantStorage::DISK);
        $backfilled = 0;
        $skipped = 0;

        $query->chunkById(100, function ($jobs) use ($generator, $storage, $disk, &$backfilled, &$skipped): void {
            foreach ($jobs as $job) {
                $width = (int) $job->width;
                if ($width <= 0 || ! $disk->exists((string) $job->r2_key)) {
                    $skipped++;

                    continue;
                }

                $bytes = (string) $disk->get((string) $job->r2_key);
                $variants = $generator->derive($bytes, $width);
                if ($variants === []) {
                    $skipped++;

                    continue;
                }

                $site = Site::withoutGlobalScope(SiteScope::class)->find($job->site_id);
                if ($site === null) {
                    $skipped++;

                    continue;
                }

                $variantKeys = [];
                foreach ($variants as $w => $variantBytes) {
                    $variantKeys[$w] = $storage->put($site, $this->variantFilename((string) $job->r2_key, $w), $variantBytes);
                }

                $job->forceFill(['variants' => $variantKeys])->save();
                $backfilled++;
            }
        });

        $this->info("Backfilled variants for {$backfilled} image(s); skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Mirror ImageRenderer's variant key scheme: the source basename + "-{width}w.webp", so a backfilled
     * variant lands at the same key a fresh render would have produced.
     */
    private function variantFilename(string $r2Key, int $width): string
    {
        $base = pathinfo($r2Key, PATHINFO_FILENAME);

        return $base.'-'.$width.'w.webp';
    }
}
