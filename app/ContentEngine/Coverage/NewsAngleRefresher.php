<?php

namespace App\ContentEngine\Coverage;

use App\ContentEngine\Drafting\DraftFailure;
use App\ContentEngine\Drafting\VoiceResolver;
use App\ContentEngine\Questions\QuestionRefresher;
use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\RefreshTrigger;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Content;
use App\Models\RefreshEvent;
use App\Models\Site;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * The Refresh action a `refresh` / `covered` coverage verdict offers: fold a candidate's news hook into the live
 * post that already covers the topic, instead of drafting a second post that splits the topic's impressions.
 * ONE narrow model call → an update block at the top of the body (`.lp-news-update`: dated, 2–4 sentences on
 * what happened and what it means for a homeowner here, the outlet cited as a link), plus a title / SEO-title /
 * meta proposal applied to the draft with the old values kept beside it (`meta.news_refresh.proposal`) so the
 * reviewer sees the diff. The rest of the body is untouched; a re-run replaces the block. The post takes the
 * drafting engine's refresh shape (version +1, `RefreshEvent` NewsDevelopment, needs_review) and the candidate
 * is closed as "folded into …" so the board shows where its hook went. No images. Never automatic.
 */
class NewsAngleRefresher
{
    public const UPDATE_CLASS = 'lp-news-update';

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly VoiceResolver $voice,
        private readonly ReviewActions $review,
    ) {}

    public function refresh(Content $post, Content $candidate): NewsAngleRefreshResult
    {
        if ($post->kind !== ContentKind::Post || $post->status !== ContentStatus::Published) {
            throw new InvalidArgumentException("Content [{$post->id}] is not a live post — the news refresh folds a hook into a published post.");
        }
        if ($candidate->kind !== ContentKind::Post || (string) $candidate->id === (string) $post->id) {
            throw new InvalidArgumentException("Content [{$candidate->id}] is not a blog candidate.");
        }

        $site = Site::withoutGlobalScopes()->find($post->site_id);
        $payload = $this->ask($post, $candidate, $site);

        $before = $this->currentHeadline($post);
        $after = $this->proposedHeadline($payload, $before);
        $changed = array_keys(array_filter($after, fn (string $v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));

        $dated = Carbon::now()->format('M Y');
        $body = $this->rebuildBody((string) $post->body, $dated, $payload['update'], (string) ($candidate->source_name ?? ''), $candidate->source_url);

        $meta = is_array($post->meta) ? $post->meta : [];
        $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];
        $seo['title'] = $after['seo_title'];
        $seo['meta_description'] = $after['meta_description'];
        $meta['seo'] = $seo;
        $meta['news_refresh'] = [
            'candidate_id' => (string) $candidate->id,
            'candidate_title' => (string) $candidate->title,
            'source_name' => $candidate->source_name,
            'source_url' => $candidate->source_url,
            'story_place' => $candidate->meta['story_place'] ?? null,
            'refreshed_at' => Carbon::now()->toIso8601String(),
            'update' => $payload['update'],
            'proposal' => ['before' => $before, 'after' => $after, 'changed' => $changed],
        ];
        unset($meta['generating_at'], $meta['draft_error'], $meta['draft_failure'], $meta['draft_failed_at']);

        $post->forceFill([
            'title' => $after['title'],
            'body' => $body,
            'meta' => $meta,
            'status' => ContentStatus::NeedsReview,
            'version' => (int) $post->version + 1,
            'last_refreshed_at' => Carbon::now(),
            'refresh_count' => (int) $post->refresh_count + 1,
        ])->save();

        RefreshEvent::create([
            'site_id' => $post->site_id,
            'content_id' => $post->id,
            'trigger' => RefreshTrigger::NewsDevelopment,
            'note' => mb_substr('Folded in the news hook “'.$candidate->title.'”'.($candidate->source_name ? ' ('.$candidate->source_name.')' : ''), 0, 500),
        ]);

        // Close the candidate where its hook went — the board shows "folded into …", and it is never re-filed.
        $cmeta = is_array($candidate->meta) ? $candidate->meta : [];
        $coverage = is_array($cmeta['coverage'] ?? null) ? $cmeta['coverage'] : [];
        $coverage['folded_into'] = (string) $post->id;
        $coverage['folded_at'] = Carbon::now()->toIso8601String();
        unset($coverage['folding_at'], $coverage['fold_error']);
        $cmeta['coverage'] = $coverage;
        $candidate->forceFill(['meta' => $cmeta])->save();
        $this->review->reject($candidate, mb_substr('Folded into “'.$post->title.'”', 0, 255));

        return new NewsAngleRefreshResult($post, $candidate, $payload['update'], ['before' => $before, 'after' => $after, 'changed' => $changed]);
    }

    /**
     * The one model call. Throws when the response carries no usable update — nothing is written.
     *
     * @return array{update: string, title: string, seo_title: string, meta_description: string}
     */
    private function ask(Content $post, Content $candidate, ?Site $site): array
    {
        $cfg = (array) config('launchpad.coverage.refresh', []);
        $updateMax = (int) ($cfg['update_max_chars'] ?? 700);
        $titleMax = (int) ($cfg['title_max_chars'] ?? 70);
        $metaMax = (int) ($cfg['meta_max_chars'] ?? 160);
        $excerptChars = (int) ($cfg['body_excerpt_chars'] ?? 6000);

        $voice = $this->voice->toArray($this->voice->active((string) $post->site_id));
        $system = 'You update an existing home-services blog post with a recent development so the one post stays the current, authoritative page on its topic. '
            .'You never invent facts, figures, prices, guarantees or locations — use only what the post and the news item say, and attribute the news to its outlet in plain words. '
            .($voice !== [] ? "Write in this brand voice:\n".json_encode($voice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'Voice: warm, plain, confident; first-person plural "we".');

        $seo = is_array($post->meta['seo'] ?? null) ? $post->meta['seo'] : [];
        $core = QuestionRefresher::stripLaneBlocks($this->withoutUpdateBlock((string) $post->body));
        $bodyText = trim((string) preg_replace('/\s+/', ' ', strip_tags($core)));
        $place = $candidate->meta['story_place'] ?? null;
        $prompt = "NEWS ITEM TO FOLD IN\n- headline: {$candidate->title}\n"
            .($candidate->angle_hint ? "- advisory angle: {$candidate->angle_hint}\n" : '')
            .($candidate->source_name ? "- outlet: {$candidate->source_name}\n" : '')
            .(is_string($place) && $place !== '' ? "- place: {$place}\n" : '')
            .(isset($candidate->meta['source_published_at']) ? '- published: '.(string) $candidate->meta['source_published_at']."\n" : '')
            .'BRAND: '.trim((string) ($site === null ? '' : $site->brand_name))."\n\n"
            ."CURRENT POST\n- title (H1): {$post->title}\n- seo_title: ".(string) ($seo['title'] ?? $post->title)."\n- meta_description: ".(string) ($seo['meta_description'] ?? '')."\n"
            .'- body (text): '.mb_substr($bodyText, 0, $excerptChars)."\n\n"
            ."PRODUCE\n"
            ."1. update: 2–4 sentences (<= {$updateMax} characters) for an \"Update\" block at the top of the post: what the news item reports (attributed to the outlet by name, no link text), then what it means for a homeowner reading this post — a concrete check or next step drawn from the post's own advice. Plain prose, no headings, no bullets, no HTML, no phone numbers.\n"
            ."2. title: the post's H1, rewritten ONLY if the development changes what the post is about; else return it unchanged. <= {$titleMax} characters.\n"
            ."3. seo_title: <= {$titleMax} characters; return the current one unchanged unless the update makes a clearer one.\n"
            ."4. meta_description: <= {$metaMax} characters, one line that now reflects the update.\n"
            .'Return ONLY a JSON object: {"update": "...", "title": "...", "seo_title": "...", "meta_description": "..."}';

        $result = $this->claude->completeDetailed($prompt, $system);
        $decoded = json_decode($this->extractJson($result->text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(DraftFailure::emptyResponse($result->text, $result)->summary());
        }
        $update = $this->clean((string) ($decoded['update'] ?? ''), $updateMax);
        if ($update === '') {
            throw new RuntimeException('The refresh call returned no update text — nothing was changed.');
        }

        return [
            'update' => $update,
            'title' => $this->clean((string) ($decoded['title'] ?? ''), $titleMax),
            'seo_title' => $this->clean((string) ($decoded['seo_title'] ?? ''), $titleMax),
            'meta_description' => $this->clean((string) ($decoded['meta_description'] ?? ''), $metaMax),
        ];
    }

    /** @return array{title: string, seo_title: string, meta_description: string} */
    private function currentHeadline(Content $post): array
    {
        $seo = is_array($post->meta['seo'] ?? null) ? $post->meta['seo'] : [];

        return [
            'title' => trim((string) $post->title),
            'seo_title' => trim((string) ($seo['title'] ?? $post->title)),
            'meta_description' => trim((string) ($seo['meta_description'] ?? '')),
        ];
    }

    /**
     * @param  array{update: string, title: string, seo_title: string, meta_description: string}  $payload
     * @param  array{title: string, seo_title: string, meta_description: string}  $before
     * @return array{title: string, seo_title: string, meta_description: string}
     */
    private function proposedHeadline(array $payload, array $before): array
    {
        return [
            'title' => $payload['title'] !== '' ? $payload['title'] : $before['title'],
            'seo_title' => $payload['seo_title'] !== '' ? $payload['seo_title'] : $before['seo_title'],
            'meta_description' => $payload['meta_description'] !== '' ? $payload['meta_description'] : $before['meta_description'],
        ];
    }

    /**
     * The body with the update block placed at the top — after the questions lane's direct-answer block when
     * the post has one (the answer stays first), else first — and any earlier update block removed.
     */
    public function rebuildBody(string $body, string $dated, string $update, string $sourceName, ?string $sourceUrl): string
    {
        $core = trim($this->withoutUpdateBlock($body));
        $source = '';
        if ($sourceName !== '') {
            $source = ' <span class="lp-news-source">Source: '
                .($sourceUrl !== null && $sourceUrl !== '' ? '<a href="'.e($sourceUrl).'" rel="nofollow noopener" target="_blank">'.e($sourceName).'</a>' : e($sourceName))
                .'</span>';
        }
        $block = '<div class="'.self::UPDATE_CLASS.'"><p><strong>Update ('.e($dated).'):</strong> '.e($update).$source.'</p></div>';

        $answer = '<div class="'.QuestionRefresher::ANSWER_CLASS.'">';
        if (str_starts_with($core, $answer)) {
            $end = strpos($core, '</div>');
            if ($end !== false) {
                $end += strlen('</div>');

                return substr($core, 0, $end)."\n".$block."\n".ltrim(substr($core, $end));
            }
        }

        return $block."\n".$core;
    }

    /** The body with any earlier update block removed (idempotent re-runs). */
    public function withoutUpdateBlock(string $body): string
    {
        return (string) preg_replace('/<div class="'.preg_quote(self::UPDATE_CLASS, '/').'">.*?<\/div>\s*/s', '', $body);
    }

    private function clean(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'…' : $value;
    }

    private function extractJson(string $raw): string
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        return ($start !== false && $end !== false && $end >= $start) ? substr($raw, $start, $end - $start + 1) : '';
    }
}
