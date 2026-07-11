<?php

namespace App\ContentEngine\BlogQueue;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The directed:reactive publishing mix (default 1:2 — freshness stays news-led, gaps close
 * steadily). ADVISORY: generation is never automatic on this platform, so the mix recommends
 * which lane the operator's next draft should come from; it never schedules one. Per-tenant
 * override on Site.directed_mix ("1:2" style), else the config default.
 */
class PublishingMix
{
    /** @return array{directed: int, reactive: int} */
    public function ratio(Site $site): array
    {
        $raw = trim((string) ($site->directed_mix ?? '')) ?: (string) config('launchpad.blog_queue.mix', '1:2');
        if (preg_match('/^(\d+)\s*:\s*(\d+)$/', $raw, $m) !== 1) {
            return ['directed' => 1, 'reactive' => 2];
        }

        return ['directed' => max(0, (int) $m[1]), 'reactive' => max(0, (int) $m[2])];
    }

    /**
     * The lane the NEXT draft should come from, judged against the current publishing cycle (the
     * last directed+reactive published posts, one ratio-window wide): while the window holds fewer
     * directed posts than the ratio asks, recommend 'directed'; else 'reactive'.
     *
     * @return 'directed'|'reactive'
     */
    public function nextLane(Site $site): string
    {
        $ratio = $this->ratio($site);
        if ($ratio['directed'] === 0) {
            return 'reactive';
        }
        if ($ratio['reactive'] === 0) {
            return 'directed';
        }

        $window = $ratio['directed'] + $ratio['reactive'];
        $recent = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->where('status', ContentStatus::Published->value)
            ->orderByDesc('published_at')
            ->limit($window)
            ->pluck('intake_type');

        $directed = $recent->filter(fn ($t) => $t === IntakeType::Directed || $t === IntakeType::Directed->value)->count();

        return $directed < $ratio['directed'] ? 'directed' : 'reactive';
    }
}
