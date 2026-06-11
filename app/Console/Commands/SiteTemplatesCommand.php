<?php

namespace App\Console\Commands;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * List a site's Elementor saved templates through the authed launchpad/v1
 * channel — the live inventory the operator maps each kit against (id, title,
 * type, last-modified, preview link). Diagnostic; read-only. The kit→template
 * mapping itself lives in the operator panel, not here.
 */
class SiteTemplatesCommand extends Command
{
    protected $signature = 'launchpad:site-templates {site : a Site id}';

    protected $description = 'List a tenant\'s Elementor saved templates via the launchpad/v1 templates endpoint.';

    public function handle(WordpressClientFactory $factory): int
    {
        $site = Site::query()->withoutGlobalScope(SiteScope::class)->find($this->argument('site'));

        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        try {
            $templates = $factory->forSite($site)->templates();
        } catch (WordpressException $e) {
            $this->error('Could not read templates — '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Site: '.($site->brand_name ?? $site->id).'  ('.$site->id.')');

        if ($templates === []) {
            $this->warn('No Elementor saved templates found on this site.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Title', 'Type', 'Modified', 'Preview'],
            array_map(static fn (array $t): array => [
                (string) ($t['id'] ?? '—'),
                (string) ($t['title'] ?? '—'),
                (string) ($t['type'] ?? '—'),
                (string) ($t['modified'] ?? '—'),
                (string) ($t['preview_url'] ?? '—'),
            ], $templates),
        );

        return self::SUCCESS;
    }
}
