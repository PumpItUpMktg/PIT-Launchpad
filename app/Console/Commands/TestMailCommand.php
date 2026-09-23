<?php

namespace App\Console\Commands;

use App\Mail\MailTestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Send one plain test email through the CONFIGURED mailer and report what actually happened — the
 * one-line check for a new mail setup on a host you cannot shell into. Says which mailer and from
 * address it used; on `log` it says the message went to the log file, not to anyone.
 */
class TestMailCommand extends Command
{
    protected $signature = 'launchpad:test-mail {to : the address to send the test to}';

    protected $description = 'Send a test email through the configured mailer and report the result';

    public function handle(): int
    {
        $to = trim((string) $this->argument('to'));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$to}' is not an email address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        $name = (string) config('mail.from.name');
        $this->line("Mailer: {$mailer} · From: {$name} <{$from}> · To: {$to}");

        try {
            Mail::to($to)->send(new MailTestMail($mailer));
        } catch (\Throwable $e) {
            $this->error('Send FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($mailer === 'log' || $mailer === 'array') {
            $this->warn("Sent to the '{$mailer}' mailer — nothing left the server. Set MAIL_MAILER to a real mailer.");

            return self::FAILURE;
        }

        $this->info("Accepted by {$mailer}. Check {$to}'s inbox (and spam) — and Postmark's Activity page if it does not arrive.");

        return self::SUCCESS;
    }
}
