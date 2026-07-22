<?php

namespace App\Console\Commands;

use App\Branding\BrandVariationBuilder;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Styling\StyleActivator;
use App\Styling\StyleVariation;
use Illuminate\Console\Command;

/**
 * Apply a site's resolved block-theme style variation to its WordPress — the Gutenberg-pivot brand
 * push from the console (the Filament "Push brand" button's CLI twin, and the escape hatch when a
 * selection didn't take). Also a diagnostic: it prints WHY the site resolves to the variation it
 * does (the logo-colors override vs the curated `style_variation` vs the recommendation), so a
 * "still showing Your brand colors" mismatch is legible before and after the push.
 *
 *   launchpad:activate-style {site} [--dry-run]
 *
 * A common cause of drift: the operator picked a curated variation (e.g. Slate & Signal) in the
 * brand picker, which stores it — but the style is applied to WordPress only on a push. Until then
 * the site keeps whatever was last activated (often the logo-derived "Your brand colors").
 */
class ActivateStyleCommand extends Command
{
    protected $signature = 'launchpad:activate-style {site : a Site id} {--variation= : force a curated variation (e.g. slate) — clears the sticky use_logo_colors override} {--logo : force the logo-derived "Your brand colors"} {--dry-run : show what WOULD be pushed, without touching WordPress}';

    protected $description = 'Apply a site\'s resolved theme.json style variation to its WordPress (Gutenberg brand push) — with a why-it-resolves diagnostic.';

    public function handle(StyleActivator $activator): int
    {
        $site = Site::query()->withoutGlobalScope(SiteScope::class)->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        // Force the selection when asked — the escape hatch for a site stuck on the logo-derived
        // "Your brand colors" because use_logo_colors is on and overrides the curated pick. Writing it
        // here is exactly what the brand picker's chooseStyle() does; this just does it from the console.
        $forceVariation = trim((string) $this->option('variation'));
        if ($this->option('logo')) {
            $site->forceFill(['use_logo_colors' => true])->save();
            $this->comment('  Forced: use_logo_colors = true (logo-derived).');
        } elseif ($forceVariation !== '') {
            $picked = StyleVariation::tryFrom($forceVariation);
            if ($picked === null) {
                $this->error("Unknown variation '{$forceVariation}'. One of: ".implode(', ', array_map(fn (StyleVariation $v): string => $v->value, StyleVariation::cases())));

                return self::FAILURE;
            }
            $site->forceFill(['style_variation' => $picked->value, 'use_logo_colors' => false])->save();
            $this->comment("  Forced: style_variation = {$picked->value}, use_logo_colors = false.");
        }
        $site->refresh();

        $this->line('Site: '.($site->brand_name ?? $site->id).'  ('.$site->id.')');

        // The resolution the push will follow: logo-colors override first, else the curated variation
        // (explicit style_variation → voice recommendation → Clean default).
        $usesLogo = (bool) $site->use_logo_colors && $activator->logoColorsAvailable($site);
        $curated = $activator->resolve($site);

        $this->line('  use_logo_colors : '.($site->use_logo_colors ? 'true' : 'false')
            .($site->use_logo_colors && ! $activator->logoColorsAvailable($site) ? ' (but no usable logo palette — falls back to curated)' : ''));
        $this->line('  style_variation : '.($site->style_variation instanceof StyleVariation ? $site->style_variation->value : '(none — using recommendation)'));

        if ($usesLogo) {
            $this->warn('  → Resolves to: '.BrandVariationBuilder::TITLE.' (logo-derived). The curated pick is IGNORED while use_logo_colors is on.');
        } else {
            $this->info('  → Resolves to: '.$curated->label().' ('.$curated->value.')');
        }

        if ($this->option('dry-run')) {
            $this->comment('  Dry run — nothing pushed.');

            return self::SUCCESS;
        }

        $result = $activator->activate($site);
        $variationValue = (string) ($result['variation'] ?? '');
        $label = $variationValue === BrandVariationBuilder::SLUG
            ? BrandVariationBuilder::TITLE
            : (StyleVariation::tryFrom($variationValue)?->label() ?? $variationValue);

        if (empty($result['updated'])) {
            $this->error('  Not applied — '.(string) ($result['error'] ?? 'unknown error.'));
            $this->line('  (If this says the variation is not in the active theme, the site\'s launchpad-blocks theme is older than the one carrying that style — update the theme, then re-run.)');

            return self::FAILURE;
        }

        $this->info("  Applied \"{$label}\" to WordPress global styles.");

        // Read back what WordPress ACTUALLY paints now, so a "colors still didn't change" report is
        // decidable at the source rather than a guessing game. A pre-0.9.16 companion returns neither
        // key — say so plainly instead of falling back to a silent, unverifiable "applied".
        if (! array_key_exists('is_block_theme', $result) && ! array_key_exists('active_colors', $result)) {
            $this->warn('  ⚠  The companion plugin on this site is older than 0.9.16 — it can\'t report what it painted.');
            $this->line('     Install launchpad-companion 0.9.16 to verify the push (and to fix the stale-cache cause of colors not changing).');

            return self::SUCCESS;
        }

        if (array_key_exists('is_block_theme', $result) && ! $result['is_block_theme']) {
            $this->warn('  ⚠  This site is NOT running a block theme — theme.json global styles are inert here.');
            $this->line('     The brand push had no visible effect: activate the launchpad-blocks block theme, then re-run.');

            return self::SUCCESS;
        }

        $colors = is_array($result['active_colors'] ?? null) ? $result['active_colors'] : [];
        if ($colors !== []) {
            $shown = [];
            foreach (['primary', 'accent', 'button'] as $slug) {
                if (isset($colors[$slug]) && $colors[$slug] !== '') {
                    $shown[] = $slug.' '.$colors[$slug];
                }
            }
            if ($shown !== []) {
                $this->line('  WordPress now paints: '.implode('   ', $shown));
                $this->line('  (If your browser still shows the old colors, that\'s a page/CDN cache — hard-refresh or purge it.)');
            }
        }

        return self::SUCCESS;
    }
}
