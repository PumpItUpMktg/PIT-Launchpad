<?php

namespace App\Console\Commands;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Console\Command;

/**
 * Operator data correction: retarget one spoke's head_keyword to a service-intent phrase.
 *
 * Volume is grounded against the persisted head_keyword, so a spoke carrying a bare product
 * noun (e.g. "basement dehumidifier") pulls retail/shopping volume that misrepresents service
 * demand. New expansions are steered to service intent at generation time, but an EXISTING
 * spoke keeps its keyword — this is the surgical fix for those. Re-run launchpad:silo-volume
 * afterward to refresh the volume.
 *
 *   launchpad:retag-spoke {site} {name} {keyword}
 */
class RetagSpokeCommand extends Command
{
    protected $signature = 'launchpad:retag-spoke
        {site : the Site id}
        {name : the spoke name (exact, case-insensitive)}
        {keyword : the new service-intent head_keyword}';

    protected $description = 'Retarget a spoke\'s head_keyword to a service-intent phrase (operator data correction).';

    public function handle(): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $name = trim((string) $this->argument('name'));
        $matches = Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get();

        if ($matches->isEmpty()) {
            $this->error("No spoke named \"{$name}\" on this site.");

            return self::FAILURE;
        }
        if ($matches->count() > 1) {
            $this->error("Ambiguous — {$matches->count()} spokes named \"{$name}\" on this site. Resolve manually.");

            return self::FAILURE;
        }

        $spoke = $matches->first();
        $old = (string) $spoke->head_keyword;
        $keyword = trim((string) $this->argument('keyword'));
        if ($keyword === '') {
            $this->error('Give a non-empty head_keyword.');

            return self::FAILURE;
        }

        $spoke->forceFill(['head_keyword' => $keyword])->save();

        $this->info("Retagged \"{$spoke->name}\": \"{$old}\" → \"{$keyword}\".");
        $this->comment('Re-run launchpad:silo-volume to refresh the volume.');

        return self::SUCCESS;
    }
}
