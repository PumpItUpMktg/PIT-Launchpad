<?php

namespace App\Console\Commands;

use App\Models\Scopes\SiteScope;
use App\Models\WireframeKit;
use App\PageBuilder\Template\KitTemplateGenerator;
use App\PageBuilder\Template\KitTemplateVerifier;
use Illuminate\Console\Command;

/**
 * Emit the bound Elementor template artifact for a kit — the #1 (CC-builds-bound)
 * artifact, generated deterministically from the kit's slot map so the binding can
 * never drift from the kit. The generated template is VERIFIED (every required slot
 * bound) before it is written, so a broken artifact never ships.
 *
 *   launchpad:generate-kit-template service --mode=native --out=service-page.elementor.json
 *
 * native    → styleable native widgets carrying __dynamic__ lp/* tags (the target).
 * shortcode → a Shortcode widget per slot ([lp_*]); the proven-live fallback.
 */
class GenerateKitTemplateCommand extends Command
{
    protected $signature = 'launchpad:generate-kit-template
        {kit : the kit page_type (e.g. service) or name (service-page)}
        {--mode=native : native|shortcode}
        {--out= : write the JSON here (defaults to stdout)}';

    protected $description = 'Generate the bound Elementor template artifact for a kit (verified before write).';

    public function handle(KitTemplateGenerator $generator, KitTemplateVerifier $verifier): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['native', 'shortcode'], true)) {
            $this->error("Unknown --mode={$mode}. Use native or shortcode.");

            return self::FAILURE;
        }

        $name = (string) $this->argument('kit');
        $kit = WireframeKit::withoutGlobalScope(SiteScope::class)
            ->whereNull('site_id')
            ->where(fn ($q) => $q->where('page_type', $name)->orWhere('name', $name))
            ->orderByDesc('version')
            ->first();

        if ($kit === null) {
            $this->error("No library kit found for [{$name}]. Has WireframeKitSeeder run?");

            return self::FAILURE;
        }

        $schema = $kit->schema();
        $template = $generator->generate($schema, $mode);

        $result = $verifier->verify($schema, $template);
        if (! $result->passes()) {
            $this->error('Generated template is missing required-slot bindings: '.implode(', ', $result->missingRequired));

            return self::FAILURE;
        }

        $json = (string) json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $out = $this->option('out');
        if ($out !== null && $out !== '') {
            file_put_contents((string) $out, $json);
            $this->info(sprintf('Wrote %s (%s mode) — %d slots bound.', $out, $mode, count($result->boundSlots)));
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
