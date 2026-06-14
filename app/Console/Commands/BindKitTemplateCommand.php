<?php

namespace App\Console\Commands;

use App\Models\Scopes\SiteScope;
use App\Models\WireframeKit;
use App\PageBuilder\Template\KitTemplateBinder;
use App\PageBuilder\Template\KitTemplateVerifier;
use Illuminate\Console\Command;

/**
 * The PRODUCTION binding step: take the designer's exported (styled) Elementor
 * template, attach the kit's lp/* data-bindings to the wf-<slot>-marked widgets,
 * and write the bound artifact — preserving the designer's layout/styling. Verifies
 * every required slot ends up bound and fails loudly (listing the gaps) if not, so
 * a half-bound design never ships.
 *
 *   launchpad:bind-kit-template service --in=designer-export.json --out=service-page.bound.json
 *
 * (launchpad:generate-kit-template is the from-scratch FALLBACK for tenants with no
 * custom design; this is the path for a real designed template.)
 */
class BindKitTemplateCommand extends Command
{
    protected $signature = 'launchpad:bind-kit-template
        {kit : the kit page_type (e.g. service) or name (service-page)}
        {--in= : path to the designer-exported Elementor template JSON}
        {--out= : write the bound JSON here (defaults to stdout)}';

    protected $description = 'Bind a kit lp/* tags into the designer-built styled Elementor template by wf-<slot> marker (verified).';

    public function handle(KitTemplateBinder $binder, KitTemplateVerifier $verifier): int
    {
        $in = (string) $this->option('in');
        if ($in === '' || ! is_file($in)) {
            $this->error('Provide --in=<path> to the designer-exported template JSON.');

            return self::FAILURE;
        }

        $template = json_decode((string) file_get_contents($in), true);
        if (! is_array($template)) {
            $this->error("Could not parse JSON from {$in}.");

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
        $bound = $binder->bind($schema, $template);

        $result = $verifier->verify($schema, $bound);
        if (! $result->passes()) {
            $this->error('Bound template still misses required slots (mark these widgets wf-<slot> with a bindable widget type): '.implode(', ', $result->missingRequired));

            return self::FAILURE;
        }

        $json = (string) json_encode($bound, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $out = $this->option('out');
        if ($out !== null && $out !== '') {
            file_put_contents((string) $out, $json);
            $this->info(sprintf('Wrote %s — %d slots bound, design preserved.', $out, count($result->boundSlots)));
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
