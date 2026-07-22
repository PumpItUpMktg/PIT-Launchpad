<?php

namespace App\Console\Commands;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Read a site's WordPress environment through the authed launchpad/v1 channel:
 * WP/PHP versions, Elementor + Elementor Pro versions (so render compatibility —
 * e.g. the Atomic Editor — is diagnosable without site access), active theme, and
 * the companion plugin version. Diagnostic; read-only.
 */
class SiteStatusCommand extends Command
{
    protected $signature = 'launchpad:site-status {site : a Site id}';

    protected $description = 'Read a tenant\'s WordPress env (WP/Elementor/Pro/theme/companion versions) via the launchpad/v1 status endpoint.';

    public function handle(WordpressClientFactory $factory): int
    {
        $site = Site::query()->withoutGlobalScope(SiteScope::class)->find($this->argument('site'));

        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        try {
            $status = $factory->forSite($site)->status();
        } catch (WordpressException $e) {
            $this->error('Could not read status — '.$e->getMessage());

            return self::FAILURE;
        }

        $theme = is_array($status['active_theme'] ?? null) ? $status['active_theme'] : [];

        $this->line('Site          : '.($site->brand_name ?? $site->id).'  ('.$site->id.')');
        $this->line('WordPress     : '.$this->v($status['wp_version'] ?? null));
        $this->line('PHP           : '.$this->v($status['php_version'] ?? null));
        $this->line('Elementor     : '.$this->v($status['elementor_version'] ?? null, 'not installed'));
        $this->line('Elementor Pro : '.$this->v($status['elementor_pro_version'] ?? null, 'not installed'));
        $this->line('Active theme  : '.$this->v($theme['name'] ?? null).' '.($theme['version'] ?? ''));
        $this->line('Companion     : '.$this->v($status['companion_version'] ?? null));

        // Block-theme + live brand colors (companion ≥ 0.9.16). Absent keys mean the site is on an
        // older plugin — the brand push can't be verified until it's updated.
        if (array_key_exists('is_block_theme', $status)) {
            $this->line('Block theme   : '.($status['is_block_theme'] ? 'yes' : 'NO — theme.json global styles are inert; brand push has no effect'));

            $colors = is_array($status['active_colors'] ?? null) ? $status['active_colors'] : [];
            $shown = [];
            foreach (['primary', 'accent', 'button'] as $slug) {
                if (isset($colors[$slug]) && is_string($colors[$slug]) && $colors[$slug] !== '') {
                    $shown[] = $slug.' '.$colors[$slug];
                }
            }
            $this->line('Live colors   : '.($shown === [] ? '—' : implode('   ', $shown)));
        } else {
            $this->comment('Block theme / live colors : (companion < 0.9.16 — update the plugin to verify the brand push)');
        }

        return self::SUCCESS;
    }

    private function v(mixed $value, string $absent = '—'): string
    {
        return is_string($value) && $value !== '' ? $value : $absent;
    }
}
