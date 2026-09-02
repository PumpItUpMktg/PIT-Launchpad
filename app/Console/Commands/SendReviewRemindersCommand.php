<?php

namespace App\Console\Commands;

use App\Mail\ReviewRequestMail;
use App\Models\ReviewRequest;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Reviews\Requests\ReviewTokens;
use App\Reviews\ReviewSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends review-request reminders (§6): day 3 and day 10 after the original send, then stop (reminder_count
 * capped at 2). Skips submitted, expired, and reminders-disabled tenants. Each reminder ROTATES the token
 * (fresh single-use link) and queues the email — nothing sent inline. Scheduled daily; the day thresholds and
 * cap come from config/reviews.php.
 */
class SendReviewRemindersCommand extends Command
{
    protected $signature = 'launchpad:send-review-reminders';

    protected $description = 'Queue day-3 / day-10 reminders for unsubmitted review requests (per-tenant toggle).';

    public function handle(ReviewTokens $tokens, ReviewSettings $settings): int
    {
        /** @var list<int> $days */
        $days = config('reviews.reminder_days', [3, 10]);
        $cap = (int) config('reviews.reminder_cap', 2);

        $requests = ReviewRequest::query()->withoutGlobalScope(SiteScope::class)
            ->whereNull('submitted_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('reminder_count', '<', $cap)
            ->whereNotNull('sent_at')
            ->get();

        $queued = 0;
        foreach ($requests as $request) {
            $threshold = $days[$request->reminder_count] ?? null; // count 0 => day 3, count 1 => day 10
            if ($threshold === null || $request->sent_at->gt(now()->subDays($threshold))) {
                continue; // not due yet
            }

            $site = Site::query()->withoutGlobalScopes()->find($request->site_id);
            if ($site === null || ! $settings->remindersEnabled($site)) {
                continue;
            }

            $email = is_string($request->payload['customer_email'] ?? null) ? $request->payload['customer_email'] : null;
            if ($email === null || $email === '') {
                continue;
            }

            $plain = $tokens->rotate($request);
            Mail::to($email)->queue(new ReviewRequestMail((string) $request->id, $plain));
            $request->forceFill(['reminder_count' => $request->reminder_count + 1])->save();
            $queued++;
        }

        $this->info("Queued {$queued} review reminder(s).");

        return self::SUCCESS;
    }
}
