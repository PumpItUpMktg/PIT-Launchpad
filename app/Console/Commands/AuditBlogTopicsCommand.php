<?php

namespace App\Console\Commands;

use App\ContentEngine\ReactiveTopicGate;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Integrations\News\NewsItem;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use DateTimeImmutable;
use Illuminate\Console\Command;

/**
 * Stage 8.6: flag PUBLISHED blog posts the reactive topic gate would now reject — the pre-gate leaks.
 *
 * The {@see ReactiveTopicGate} (config-tuned allowlist + finance/governance deny-context + footprint)
 * keeps off-target municipal-utility-finance and out-of-footprint news OUT of the funnel, but posts
 * published BEFORE the gate landed are already live. This runs each published post back through the same
 * gate and reports the ones it would reject (off_topic / out_of_footprint) so the operator can prune them.
 *
 * READ-ONLY — it never unpublishes or pushes. The operator prunes via the Operate → Blog surface + a WP
 * takedown (Eric runs the WordPress side).
 */
class AuditBlogTopicsCommand extends Command
{
    protected $signature = 'launchpad:audit-blog-topics {--site= : Site id or brand name (required)}';

    protected $description = 'Flag published blog posts the reactive topic gate would now reject (off-topic municipal-finance / out-of-footprint) — the pre-gate leaks to prune. Read-only.';

    public function handle(ReactiveTopicGate $gate): int
    {
        $site = $this->resolveSite();
        if ($site === null) {
            $this->error('Site not found — pass --site=<id|brand name>.');

            return self::FAILURE;
        }

        /** @var list<string> $footprint */
        $footprint = array_map(fn (string $s): string => strtoupper(trim($s)), (array) config('launchpad.footprint.states', []));

        $posts = Content::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug')
            ->orderBy('published_at')
            ->get(['id', 'title', 'slug', 'source_name', 'meta', 'published_at']);

        if ($posts->isEmpty()) {
            $this->info("No published blog posts for {$site->brand_name}.");

            return self::SUCCESS;
        }

        /** @var list<array{title: string, slug: string, reason: string}> $flagged */
        $flagged = [];
        foreach ($posts as $post) {
            $meta = is_array($post->meta) ? $post->meta : [];
            $summary = is_array($meta['seo'] ?? null) ? (string) ($meta['seo']['meta_description'] ?? '') : '';
            $item = new NewsItem(
                externalId: (string) $post->id,
                title: (string) $post->title,
                summary: $summary,
                sourceName: (string) ($post->source_name ?? ''),
                publishedAt: $post->published_at?->toDateTimeImmutable() ?? new DateTimeImmutable('@0'),
            );

            $reason = $gate->rejection($item, $footprint);
            if ($reason !== null) {
                $flagged[] = ['title' => (string) $post->title, 'slug' => (string) $post->slug, 'reason' => $reason];
            }
        }

        $this->line("Blog-topic audit for <info>{$site->brand_name}</info> — {$posts->count()} published post(s):");
        if ($flagged === []) {
            $this->info('  All on-topic and in-footprint. Nothing to prune.');

            return self::SUCCESS;
        }

        foreach ($flagged as $f) {
            $this->line("  · <comment>{$f['reason']}</comment>  /{$f['slug']}  — {$f['title']}");
        }

        $this->newLine();
        $this->warn(count($flagged).' post(s) the current gate would REJECT (pre-gate leaks). Prune via Operate → Blog + a WP takedown.');

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $arg = $this->option('site');

        return is_string($arg) && $arg !== ''
            ? Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first()
            : null;
    }
}
