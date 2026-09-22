<?php

namespace App\Console\Commands;

use App\Enums\RedirectSource;
use App\Jobs\PublishRedirects;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\PublishRedirectsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Repoint (or deactivate) a single 301 for a tenant — the fix for a stale redirect left behind by an old
 * build, e.g. `/hoboken/` still 301'ing to a blog post instead of the `/hoboken-nj` location page.
 *
 * Upserts the control-plane {@see Redirect} row keyed on `from_url` (matching the companion plugin, which
 * upserts by `from_url` — so a re-push OVERRIDES whatever the live site currently has for that path).
 * `--delete` deactivates the control-plane row instead of repointing it. PREVIEW BY DEFAULT; `--apply`
 * writes, and `--push` then queues the redirect push to WordPress.
 */
class FixRedirectCommand extends Command
{
    protected $signature = 'launchpad:fix-redirect
        {--site= : Site id or brand name (required)}
        {--from= : The source path to fix, e.g. /hoboken/ (required)}
        {--to= : The correct destination, e.g. /hoboken-nj (required unless --delete)}
        {--code=301 : HTTP status for the redirect}
        {--gone : Emit 410 Gone for --from (flush a dead / out-of-footprint legacy URL from the index; no --to needed)}
        {--delete : Deactivate the redirect for --from instead of repointing it}
        {--apply : Actually write the change (default is a preview)}
        {--push : After applying, queue the redirect push to WordPress}
        {--now : After applying, push to WordPress immediately from this process instead of queueing}';

    protected $description = 'Repoint or deactivate a stale 301 for a tenant (e.g. /hoboken/ → /hoboken-nj). Preview by default; --apply to write, --push to send to WP.';

    public function handle(PublishRedirectsService $redirects): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            return self::FAILURE;
        }

        $from = $this->normalize((string) $this->option('from'));
        if ($from === '') {
            $this->error('--from is required (the source path, e.g. /hoboken/).');

            return self::FAILURE;
        }

        $delete = (bool) $this->option('delete');
        $gone = (bool) $this->option('gone');
        // --gone flushes the URL (410 Gone) with no destination — the plugin emits the status, no redirect.
        $to = $gone ? '' : $this->normalize((string) $this->option('to'));
        if (! $delete && ! $gone && $to === '') {
            $this->error('--to is required unless --delete / --gone (the correct destination, e.g. /hoboken-nj).');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $code = $gone ? 410 : (int) $this->option('code');

        $existing = Redirect::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('from_url', $from)
            ->first();

        $current = $existing !== null ? "{$existing->from_url} → {$existing->to_url} ({$existing->code}, {$existing->status})" : 'none';
        $this->line("<info>{$site->brand_name}</info> — existing redirect for {$from}: {$current}");

        if ($delete) {
            $this->line($existing === null
                ? "  • nothing to deactivate for {$from}"
                : "  • would deactivate {$from}");
        } elseif ($gone) {
            $this->line("  • would flush {$from} → 410 Gone");
        } else {
            $this->line("  • would set {$from} → {$to} ({$code}, active)");
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Preview only — nothing changed. Re-run with --apply to write'.($this->option('push') ? ', then it will push to WP.' : '.'));

            return self::SUCCESS;
        }

        if ($delete) {
            if ($existing !== null) {
                $existing->forceFill(['status' => 'inactive'])->save();
            }
        } else {
            Redirect::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                ['site_id' => $site->id, 'from_url' => $from],
                ['to_url' => $to, 'code' => $code, 'status' => 'active', 'source' => RedirectSource::Migration->value],
            );
        }

        $this->info('Redirect updated.');

        // --now pushes from THIS process. The redirect push is one HTTP call to the plugin — seconds, on the
        // console with no FPM clock — and queueing it puts that call behind whatever else is on `default`.
        // On Sump Pump Gurus that was twenty-six GeneratePage jobs, a Sonnet draft and a render each, and
        // a fix written at the operator's desk had not reached WordPress eleven minutes later. "Queued" is
        // not the finish line; a 301 on the live URL is.
        if ($this->option('now')) {
            try {
                $redirects->publish($site);
            } catch (Throwable $e) {
                $this->error('Push to WordPress failed: '.$e->getMessage());
                $this->comment('The control-plane row is written; re-run with --now once the site answers, or --push to queue it.');

                return self::FAILURE;
            }
            $this->info(sprintf('Pushed to WordPress now. Confirm with: curl -sI https://%s%s  → expect %d → %s',
                parse_url((string) $site->domain_url, PHP_URL_HOST) ?: 'the-site', $from, $code, $to !== '' ? $to : '(gone)'));

            return self::SUCCESS;
        }

        if ($this->option('push')) {
            PublishRedirects::dispatch((string) $site->id);
            $this->info('Queued the redirect push to WordPress (the plugin upserts by from_url, overriding the stale rule).');
            $this->comment('Queued is not live. A busy default lane can hold this behind page generation — --now pushes immediately.');
        } else {
            $this->comment('Not pushed — run with --push to queue it, or --now to send it to the live site immediately.');
        }

        return self::SUCCESS;
    }

    /** Leading-slash, trimmed path (so "/hoboken/" and "hoboken/" match the same rule). */
    private function normalize(string $path): string
    {
        $path = trim($path);

        return $path === '' ? '' : '/'.ltrim($path, '/');
    }

    private function resolveSite(): ?Site
    {
        $arg = trim((string) $this->option('site'));
        if ($arg === '') {
            $this->error('--site is required (id or brand name).');

            return null;
        }

        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");
        }

        return $site;
    }
}
