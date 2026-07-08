<?php

namespace App\Console\Commands;

use App\Branding\LogoHeaderTone;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\Chrome\SiteProfileAssembler;
use App\Publishing\TenantStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Backfill the logo → dark/light HEADER TONE onto existing tenants. The tone is normally decided at logo
 * intake ({@see LogoHeaderTone}), so a logo uploaded BEFORE that feature has no `header_tone` on its
 * `logo_set` and the header defaults to dark — which loses a dark logo on the dark bar. This re-reads the
 * stored logo from R2, evaluates the tone, saves it, and (with --push) re-pushes the site profile so the
 * live header updates. Doubles as a diagnostic: it prints the tone each logo evaluates to.
 */
class EvaluateLogoToneCommand extends Command
{
    protected $signature = 'launchpad:evaluate-logo-tone
        {site? : a Site id (default: every site that has a logo)}
        {--force : recompute even when a header_tone is already stored}
        {--push : re-push the site profile after, so the live header updates}';

    protected $description = 'Evaluate whether each uploaded logo suits a dark or light header and backfill header_tone (logos uploaded before the feature had none → defaulted to dark).';

    public function handle(LogoHeaderTone $tone, SiteProfileAssembler $assembler, WordpressClientFactory $factory): int
    {
        $siteId = $this->argument('site');

        $brandings = SiteBranding::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', (string) $siteId))
            ->get();

        if ($brandings->isEmpty()) {
            $this->warn('No matching site branding.');

            return self::SUCCESS;
        }

        $evaluated = 0;
        foreach ($brandings as $branding) {
            $set = is_array($branding->logo_set) ? $branding->logo_set : [];
            $r2Key = trim((string) ($set['r2_key'] ?? ''));
            if ($r2Key === '') {
                continue; // no logo stored — the header uses the text brand name
            }
            if (! $this->option('force') && isset($set['header_tone'])) {
                $this->line(sprintf('%s → already %s (use --force to recompute)', $branding->site_id, (string) $set['header_tone']));

                continue;
            }

            try {
                $bytes = Storage::disk(TenantStorage::DISK)->get($r2Key);
            } catch (Throwable $e) {
                $this->warn(sprintf('%s → could not read logo (%s)', $branding->site_id, $e->getMessage()));

                continue;
            }
            if (! is_string($bytes) || $bytes === '') {
                $this->warn(sprintf('%s → empty logo', $branding->site_id));

                continue;
            }

            $ext = strtolower(trim((string) ($set['ext'] ?? pathinfo($r2Key, PATHINFO_EXTENSION))));
            $headerTone = $tone->forLogo($bytes, $ext);
            $set['header_tone'] = $headerTone;
            $branding->update(['logo_set' => $set]);
            $evaluated++;

            $this->line(sprintf(
                '%s → header_tone: <info>%s</info>%s',
                $branding->site_id,
                $headerTone,
                $headerTone === LogoHeaderTone::LIGHT ? ' (light header — the logo reads as dark)' : ' (dark header — the logo reads as light)',
            ));

            if ($this->option('push')) {
                $this->pushProfile($factory, $assembler, (string) $branding->site_id);
            }
        }

        $this->info("Done — evaluated {$evaluated} logo(s).".($this->option('push') ? '' : ' Re-run with --push (or launchpad:sync-site-profile) to update the live header.'));

        return self::SUCCESS;
    }

    private function pushProfile(WordpressClientFactory $factory, SiteProfileAssembler $assembler, string $siteId): void
    {
        $site = Site::withoutGlobalScope(SiteScope::class)->find($siteId);
        if ($site === null) {
            return;
        }

        try {
            $factory->forSite($site)->pushSiteProfile($assembler->assemble($site));
            $this->line('  ↳ site profile re-pushed to WordPress.');
        } catch (Throwable $e) {
            $this->warn('  ↳ profile push failed: '.$e->getMessage());
        }
    }
}
