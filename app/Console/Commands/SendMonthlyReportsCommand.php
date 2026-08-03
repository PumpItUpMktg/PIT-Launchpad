<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Mail\MonthlyReportMail;
use App\Models\Site;
use App\Models\User;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the §7c monthly performance report (PDF attached) to each client. One email per Site, sent
 * to every client User on the Site's Account. Defaults to the month that just ended (so the 1st-of-
 * month schedule reports a complete month); --month=YYYY-MM and --site= override for a manual resend.
 * --dry-run reports who would receive it without sending. A Site whose Account has no client users is
 * skipped (nobody to send to). Idempotency is the operator's call — re-running resends.
 */
class SendMonthlyReportsCommand extends Command
{
    protected $signature = 'launchpad:send-monthly-reports {--site= : Only this Site id} {--month= : Report month as YYYY-MM (default: last month)} {--dry-run : List recipients without sending}';

    protected $description = 'Email each client their monthly keyword-improvement report (PDF attached).';

    public function handle(): int
    {
        $monthKey = $this->resolveMonth();
        if ($monthKey === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sites = $this->targetSites();

        $sent = 0;
        $skipped = 0;

        foreach ($sites as $site) {
            $recipients = $this->recipients($site);

            if ($recipients->isEmpty()) {
                $skipped++;
                $this->line("  skip  {$site->brand_name} — no client users on the account");

                continue;
            }

            $emails = $recipients->pluck('email')->implode(', ');
            $this->line(sprintf('  %s  %s → %s', $dryRun ? 'would' : 'send ', $site->brand_name, $emails));

            if (! $dryRun) {
                Mail::to($recipients->all())->send(new MonthlyReportMail($site, $monthKey));
            }
            $sent++;
        }

        $this->info(sprintf('%s %d report(s) for %s (%d site(s) skipped).', $dryRun ? 'Would send' : 'Queued', $sent, $monthKey, $skipped));

        return self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function targetSites(): Collection
    {
        $query = Site::query()->with('account')->orderBy('brand_name');

        if (is_string($siteId = $this->option('site')) && $siteId !== '') {
            $query->whereKey($siteId);
        }

        return $query->get();
    }

    /** The client users on a site's account — the report recipients. @return \Illuminate\Support\Collection<int, User> */
    private function recipients(Site $site): Collection
    {
        if ($site->account === null) {
            return collect();
        }

        return $site->account->users()
            ->where('users.role', UserRole::Client->value)
            ->whereNotNull('users.email')
            ->get()
            ->unique('email')
            ->values();
    }

    private function resolveMonth(): ?string
    {
        $raw = $this->option('month');
        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::createFromFormat('Y-m', $raw)->startOfMonth()->format('Y-m');
            } catch (InvalidFormatException) {
                $this->error("Invalid --month '{$raw}' — expected YYYY-MM.");

                return null;
            }
        }

        return Carbon::now()->subMonthNoOverflow()->format('Y-m');
    }
}
