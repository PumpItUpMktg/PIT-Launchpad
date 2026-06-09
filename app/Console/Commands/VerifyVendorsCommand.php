<?php

namespace App\Console\Commands;

use App\Console\VendorProbes\VendorProbe;
use App\Console\VendorProbes\VendorProbeRegistry;
use Illuminate\Console\Command;

/**
 * Live vendor-path verification (diagnostic). Runs every registered VendorProbe
 * once against the REAL API and reports LIVE / SKIP / FAIL.
 *
 * Each committed vendor ships its own probe under App\Console\VendorProbes\Probes;
 * the registry auto-discovers them, so a new adapter adds a probe class rather
 * than editing this command. Probes no-op (SKIP) when their credentials are
 * absent, so it is safe to run before keys land. This makes real outbound calls
 * — it is console-only and must never be wired into CI / test runs.
 */
class VerifyVendorsCommand extends Command
{
    protected $signature = 'launchpad:verify-vendors';

    protected $description = 'Run every registered vendor probe once against LIVE and report LIVE/SKIP/FAIL. Makes real outbound calls — never run in CI.';

    public function handle(VendorProbeRegistry $registry): int
    {
        $this->warn('Live vendor verification — one real outbound call per registered vendor probe (skipped when credentials are absent).');
        $this->newLine();

        $probes = $registry->all();
        $pad = max(array_map(fn (VendorProbe $p) => strlen($p->label()), $probes) ?: [0]);

        // Run each probe once; reuse the captured lines for the summary so a
        // probe never fires twice.
        $lines = array_map(fn (VendorProbe $p) => $p->run()->line($p->label(), $pad), $probes);

        foreach ($lines as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->info('--- LIVE/FAIL summary ---');
        foreach ($lines as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
