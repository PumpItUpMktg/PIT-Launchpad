<?php

namespace App\Console\Commands;

use App\Branding\BrandBrief;
use App\Branding\BrandGenerator;
use App\Branding\IndustryResolver;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use Illuminate\Console\Command;

/**
 * Preview the Phase 3 multi-candidate brand generator for a real tenant (the §8
 * verify-from-live hook, ahead of the Phase 4 interview surface): recommend a
 * structure from the personality (unless --structure pins it), then generate the
 * validated candidates and print the palette/fonts/rationale + any guard
 * adjustments. Read-only — it never saves or pushes.
 *
 *   launchpad:brand-candidates {site} [--personality=] [--structure=] [--count=]
 */
class BrandCandidatesCommand extends Command
{
    protected $signature = 'launchpad:brand-candidates {site : a Site id}
        {--personality=trustworthy : a BrandBrief personality key}
        {--structure= : pin trust|bold|warm (else the AI recommends one)}
        {--count=4 : how many candidates}';

    protected $description = 'Preview the AI brand candidates (structure rec + palette/type) for a tenant — read-only.';

    public function handle(BrandGenerator $generator, IndustryResolver $industry): int
    {
        $site = Site::query()->withoutGlobalScope(SiteScope::class)->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        $existing = is_array($branding?->palette) ? array_values(array_filter($branding->palette, 'is_string')) : [];

        $brief = new BrandBrief(
            industry: $industry->for($site),
            personality: (string) $this->option('personality'),
            colorAnchorsUse: $existing, // harmonize around any existing brand colors
        );

        $structure = (string) ($this->option('structure') ?: '');
        if (! in_array($structure, ['trust', 'bold', 'warm'], true)) {
            $rec = $generator->recommendStructure($brief);
            $structure = $rec->slug;
            $this->info("Recommended structure: {$rec->slug} — {$rec->rationale}");
        }

        $set = $generator->generateCandidates($brief, $structure, (int) $this->option('count'));

        $this->line("Industry: {$brief->industry}  ·  Structure: {$set->structure}");
        foreach ($set->candidates as $i => $c) {
            $tag = $c->recommended ? ' [RECOMMENDED]' : '';
            $this->line('');
            $this->line('Candidate '.($i + 1).$tag);
            $this->line('  fonts:  '.$c->typography['heading'].' / '.$c->typography['body']);
            $this->line('  colors: '.collect($c->palette)->map(fn ($v, $k) => "{$k}={$v}")->implode('  '));
            $this->line('  why:    '.$c->rationale);
            foreach ($c->adjustments as $adjustment) {
                $this->warn('  fixed:  '.$adjustment);
            }
        }

        return self::SUCCESS;
    }
}
