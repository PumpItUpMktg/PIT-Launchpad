<?php

namespace App\Console\Commands;

use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\PageBuilder\Template\KitTemplateArtifacts;
use Illuminate\Console\Command;

/**
 * Push a tenant's bound kit template(s) into its WordPress Theme Builder — the H2
 * import-on-provision step (option A: the engine sends the artifact; the companion
 * plugin imports it via POST /kit-template and sets the lp_kit Display Condition).
 *
 *   launchpad:push-kit-template {site}                  # every kit with an artifact
 *   launchpad:push-kit-template {site} --kit=service-page
 *   launchpad:push-kit-template {site} --in=path/to/template.json --kit=service-page
 *
 * Idempotent: the plugin updates the same per-kit elementor_library template on a
 * re-push, so this is safe to re-run on every provision/refresh. The Display
 * Condition requires Elementor Pro on the client site — when absent the import
 * still lands and the response flags condition_set:false for an operator to set
 * once by hand.
 */
class PushKitTemplateCommand extends Command
{
    protected $signature = 'launchpad:push-kit-template
        {site : a Site id}
        {--kit= : a single kit (page_type name, e.g. service-page); default all kits with an artifact}
        {--mode=native : which generator fallback to use when no bound artifact exists (native|shortcode)}
        {--in= : an explicit artifact JSON path (requires --kit)}';

    protected $description = 'Push bound kit template(s) into a tenant\'s WordPress Theme Builder via launchpad/v1.';

    public function handle(WordpressClientFactory $factory, KitTemplateArtifacts $artifacts): int
    {
        $site = Site::query()->withoutGlobalScope(SiteScope::class)->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['native', 'shortcode'], true)) {
            $this->error("Unknown --mode={$mode}. Use native or shortcode.");

            return self::FAILURE;
        }

        $kits = $this->resolveKits($artifacts);
        if ($kits === []) {
            $this->warn('No kit template artifacts found to push.');

            return self::SUCCESS;
        }

        try {
            $client = $factory->forSite($site);
        } catch (WordpressException $e) {
            $this->error('No WordPress connection — '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Site: '.($site->brand_name ?? $site->id).'  ('.$site->id.')');

        $explicitIn = (string) $this->option('in');
        $failed = false;

        foreach ($kits as $kit) {
            $template = $explicitIn !== ''
                ? $this->loadExplicit($explicitIn)
                : $artifacts->load($kit, $mode);

            if ($template === null) {
                $this->error("  {$kit}: no artifact (looked for ".$artifacts->dir()."/{$kit}.*.elementor.json).");
                $failed = true;

                continue;
            }

            try {
                $result = $client->upsertKitTemplate([
                    'kit' => $kit,
                    'title' => (string) ($template['title'] ?? ('Launchpad Kit — '.$kit)),
                    'template' => $template,
                ]);
            } catch (WordpressException $e) {
                $this->error("  {$kit}: push failed — ".$e->getMessage());
                $failed = true;

                continue;
            }

            if (isset($result['error'])) {
                $this->error("  {$kit}: rejected — ".(string) $result['error']);
                $failed = true;

                continue;
            }

            $this->reportKit($kit, $result);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveKits(KitTemplateArtifacts $artifacts): array
    {
        $kit = (string) $this->option('kit');
        if ($kit !== '') {
            return [$kit];
        }

        return $artifacts->availableKits();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadExplicit(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function reportKit(string $kit, array $result): void
    {
        $verb = ! empty($result['created']) ? 'created' : 'updated';
        $id = (int) ($result['template_id'] ?? 0);

        if (! empty($result['condition_set'])) {
            $this->info("  {$kit}: {$verb} template #{$id}; lp_kit Display Condition set.");

            return;
        }

        $rule = (string) ($result['condition']['rule'] ?? '');
        $hint = empty($result['pro'])
            ? 'Elementor Pro not active — set the Display Condition once: Singular → By Term → Launchpad Kit → '.$kit
            : 'condition not auto-set'.($rule !== '' ? " ({$rule})" : '');
        $this->warn("  {$kit}: {$verb} template #{$id}; ".$hint);
    }
}
