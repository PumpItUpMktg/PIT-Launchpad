<?php

namespace App\Console\Commands;

use App\Interview\Invites\InterviewInvites;
use App\Interview\Invites\IssuedInvite;
use App\Models\InterviewInvite;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Operator control for the client interview link (relay PR 1): issue (re-issue) a link for a site, revoke it,
 * or show the live one. Issuing prints the one-time plaintext token — it is never stored and cannot be shown
 * again; a lost link is re-issued. The public page that consumes it lands in PR 2; the Setup surface for
 * send / resend / revoke in PR 3 — this command is the operator action until then.
 */
class InterviewInviteCommand extends Command
{
    protected $signature = 'launchpad:interview-invite
        {site : Site id or brand name}
        {--revoke : Revoke the live link instead of issuing one}
        {--status : Show the live link (if any) without changing anything}
        {--email= : Who the link is addressed to (informational)}
        {--ttl= : Lifetime in days (default: launchpad.interview_invite_ttl_days)}';

    protected $description = 'Issue, revoke, or show the client-facing interview link for a site (one live link per site; issuing revokes the previous).';

    public function handle(InterviewInvites $invites): int
    {
        $needle = (string) $this->argument('site');
        $site = Site::withoutGlobalScopes()->find($needle)
            ?? Site::withoutGlobalScopes()->where('brand_name', $needle)->first();
        if ($site === null) {
            $this->error("No site matching '{$needle}'.");

            return self::FAILURE;
        }

        $live = $invites->live($site);

        if ((bool) $this->option('status')) {
            $this->describe($site, $live);

            return self::SUCCESS;
        }

        if ((bool) $this->option('revoke')) {
            $revoked = $invites->revokeLive($site);
            $this->info($revoked > 0
                ? "{$site->brand_name}: revoked {$revoked} link(s) — the old link no longer resolves."
                : "{$site->brand_name}: no live link to revoke.");

            return self::SUCCESS;
        }

        $ttlOption = $this->option('ttl');
        $ttl = is_string($ttlOption) && ctype_digit($ttlOption) ? (int) $ttlOption : null;
        $email = $this->option('email');

        $issued = $invites->issue($site, is_string($email) ? $email : null, null, $ttl);

        $this->line("<info>{$site->brand_name}</info>  ({$site->id})");
        if ($live !== null) {
            $this->comment("  Previous link (issued {$live->issued_at->toDateString()}) revoked.");
        }
        $this->line("  Token:   {$issued->plaintext}");
        $this->line('  Link:    '.route('interview.show', ['token' => $issued->plaintext]));
        $this->line("  Expires: {$issued->invite->expires_at->toDateTimeString()} ({$this->ttlLabel($issued)})");
        $this->comment('  The token is shown ONCE — it is stored hashed and cannot be recovered. Re-run to issue a new one.');

        return self::SUCCESS;
    }

    private function describe(Site $site, ?InterviewInvite $live): void
    {
        $this->line("<info>{$site->brand_name}</info>  ({$site->id})");
        if ($live === null) {
            $this->line('  No live interview link.');

            return;
        }
        $this->line(sprintf(
            '  Live link issued %s · expires %s · opened %d time(s)%s · interview %s',
            $live->issued_at->toDateString(),
            $live->expires_at->toDateString(),
            $live->open_count,
            $live->last_opened_at !== null ? ' (last '.$live->last_opened_at->toDateTimeString().')' : '',
            $live->interview_id ?? 'not started',
        ));
    }

    private function ttlLabel(IssuedInvite $issued): string
    {
        return $issued->invite->issued_at->diffInDays($issued->invite->expires_at).' days';
    }
}
