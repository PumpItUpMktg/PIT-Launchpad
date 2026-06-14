<?php

namespace App\Console\Commands;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\BrandKitAssembler;
use Illuminate\Console\Command;

/**
 * Push a tenant's intake brand (palette + typography) into its WordPress Elementor
 * Global Kit — the engine half of C5 (brand intake → Elementor global kit). This
 * is what makes the templates' `__globals__` references paint the client's brand
 * instead of theme defaults. Idempotent: re-running overwrites the same kit slots.
 *
 *   launchpad:push-brand-kit {site}
 *
 * Provisioning normally fires this automatically as the first step of
 * launchpad:push-kit-template (one pass); this command re-pushes brand alone.
 */
class PushBrandKitCommand extends Command
{
    protected $signature = 'launchpad:push-brand-kit {site : a Site id}';

    protected $description = 'Push a tenant\'s intake palette + typography into its WordPress Elementor Global Kit.';

    public function handle(WordpressClientFactory $factory, BrandKitAssembler $assembler): int
    {
        $site = Site::query()->withoutGlobalScope(SiteScope::class)->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $payload = $assembler->forSite((string) $site->id);
        if ($payload === null) {
            $this->warn('No brand captured for this site yet (no palette or typography in intake) — nothing to push.');

            return self::SUCCESS;
        }

        try {
            $result = $factory->forSite($site)->upsertBrandKit($payload);
        } catch (WordpressException $e) {
            $this->error('Brand push failed — '.$e->getMessage());

            return self::FAILURE;
        }

        $this->reportResult($site, $result);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function reportResult(Site $site, array $result): void
    {
        $this->line('Site: '.($site->brand_name ?? $site->id).'  ('.$site->id.')');

        if (empty($result['updated'])) {
            $this->warn('  Brand not applied — '.(string) ($result['error'] ?? 'no active Elementor Global Kit on the site.'));

            return;
        }

        $this->info(sprintf(
            '  Brand applied to Global Kit #%d — %d color(s), %d font(s).',
            (int) ($result['kit_id'] ?? 0),
            (int) ($result['colors_set'] ?? 0),
            (int) ($result['fonts_set'] ?? 0),
        ));
    }
}
