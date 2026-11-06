<?php

namespace App\ContentEngine\Questions;

use App\ContentEngine\Drafting\DraftFailure;
use App\ContentEngine\Drafting\VoiceResolver;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\RefreshTrigger;
use App\Integrations\Claude\ClaudeClient;
use App\Models\Content;
use App\Models\RefreshEvent;
use App\Models\Site;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * The Refresh action for a post the questions lane marked (meta.question_refresh): the post already
 * answers a question searchers ask, but ranks below the covered bound. Rather than rewrite it, one
 * narrow model call produces
 *
 *  1. a DIRECT ANSWER to the question, inserted at the top of the body (what a question-shaped query
 *     rewards — a concise answer Google can lift);
 *  2. a short Q&A section at the end of the body for the sibling phrasings, mirrored into the `faq`
 *     slot the companion plugin's FAQPage schema reads;
 *  3. a title / SEO-title / meta-description proposal, applied to the draft with the old values kept
 *     beside it (meta.question_refresh.proposal) so the reviewer sees the diff and can revert a field.
 *
 * The body outside the two marked blocks is untouched — the passages that already earn the current
 * position stay. The row then takes the drafting engine's refresh shape: version +1, refresh_count +1,
 * last_refreshed_at, one RefreshEvent (SearchQuestion), status needs_review. Nothing publishes here;
 * approve → release → PublishContent updates the same WordPress post (idempotent by ULID, slug kept).
 * Re-running replaces the two blocks, never stacks them. No images are rendered.
 */
class QuestionRefresher
{
    public const ANSWER_CLASS = 'lp-question-answer';

    public const FAQ_CLASS = 'lp-question-faq';

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly VoiceResolver $voice,
    ) {}

    public function refresh(Content $post): QuestionRefreshResult
    {
        if ($post->kind !== ContentKind::Post) {
            throw new InvalidArgumentException("Content [{$post->id}] is a page — the question refresh is for posts.");
        }
        $mark = $post->meta['question_refresh'] ?? null;
        if (! is_array($mark) || trim((string) ($mark['query'] ?? '')) === '') {
            throw new InvalidArgumentException("Content [{$post->id}] carries no question mark — run launchpad:mine-questions --execute first.");
        }
        $question = trim((string) $mark['query']);
        $phrasings = array_values(array_filter(array_map('strval', is_array($mark['phrasings'] ?? null) ? $mark['phrasings'] : []), fn (string $p): bool => trim($p) !== ''));

        $site = Site::withoutGlobalScopes()->find($post->site_id);
        $payload = $this->ask($post, $site, $question, $phrasings);

        $before = $this->currentHeadline($post);
        $after = $this->proposedHeadline($payload, $before);
        $changed = array_keys(array_filter($after, fn (string $v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));

        $faq = $payload['faq'];
        $body = $this->rebuildBody((string) $post->body, $question, $payload['answer'], $faq);

        $slots = is_array($post->slot_payload) ? $post->slot_payload : [];
        $slots['faq'] = $this->mergeFaqSlot($slots['faq'] ?? null, $mark['faq'] ?? null, $faq);

        $meta = is_array($post->meta) ? $post->meta : [];
        $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];
        $seo['title'] = $after['seo_title'];
        $seo['meta_description'] = $after['meta_description'];
        $meta['seo'] = $seo;
        $mark['refreshed_at'] = Carbon::now()->toIso8601String();
        $mark['answer'] = $payload['answer'];
        $mark['faq'] = $faq;
        $mark['proposal'] = ['before' => $before, 'after' => $after, 'changed' => $changed];
        $meta['question_refresh'] = $mark;
        unset($meta['generating_at'], $meta['draft_error'], $meta['draft_failure'], $meta['draft_failed_at']);

        $post->forceFill([
            'title' => $after['title'],
            'body' => $body,
            'slot_payload' => $slots,
            'meta' => $meta,
            'status' => ContentStatus::NeedsReview,
            'version' => (int) $post->version + 1,
            'last_refreshed_at' => Carbon::now(),
            'refresh_count' => (int) $post->refresh_count + 1,
        ])->save();

        RefreshEvent::create([
            'site_id' => $post->site_id,
            'content_id' => $post->id,
            'trigger' => RefreshTrigger::SearchQuestion,
            'note' => mb_substr('Refreshed for the search question “'.$question.'”', 0, 500),
        ]);

        return new QuestionRefreshResult($post, $question, $payload['answer'], ['before' => $before, 'after' => $after, 'changed' => $changed], count($faq));
    }

    /**
     * The one model call. Throws (a DraftFailure-shaped RuntimeException) when the response carries no
     * usable answer — the caller records the failure on the row; nothing is written.
     *
     * @param  list<string>  $phrasings
     * @return array{answer: string, faq: list<array{question: string, answer: string}>, title: string, seo_title: string, meta_description: string}
     */
    private function ask(Content $post, ?Site $site, string $question, array $phrasings): array
    {
        $cfg = (array) config('launchpad.questions.refresh', []);
        $answerMax = (int) ($cfg['answer_max_chars'] ?? 700);
        $faqMax = (int) ($cfg['faq_max'] ?? 4);
        $faqAnswerMax = (int) ($cfg['faq_answer_max_chars'] ?? 400);
        $titleMax = (int) ($cfg['title_max_chars'] ?? 70);
        $metaMax = (int) ($cfg['meta_max_chars'] ?? 160);
        $excerptChars = (int) ($cfg['body_excerpt_chars'] ?? 6000);

        $voice = $this->voice->toArray($this->voice->active((string) $post->site_id));
        $system = 'You refresh an existing home-services blog post so it directly answers a question real searchers typed into Google. '
            .'You never invent facts, prices, guarantees or locations — reuse what the post already says. '
            .($voice !== [] ? "Write in this brand voice:\n".json_encode($voice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'Voice: warm, plain, confident; first-person plural "we".');

        $seo = is_array($post->meta['seo'] ?? null) ? $post->meta['seo'] : [];
        $bodyText = trim((string) preg_replace('/\s+/', ' ', strip_tags($this->withoutLaneBlocks((string) $post->body))));
        $prompt = "SEARCH QUESTION (the lead phrasing searchers use): \"{$question}\"\n"
            .($phrasings !== [] ? 'ALSO PHRASED: '.implode(' · ', array_map(fn (string $p): string => "\"{$p}\"", array_slice($phrasings, 0, 8)))."\n" : '')
            .'BRAND: '.trim((string) ($site === null ? '' : $site->brand_name))."\n\n"
            ."CURRENT POST\n- title (H1): {$post->title}\n- seo_title: ".(string) ($seo['title'] ?? $post->title)."\n- meta_description: ".(string) ($seo['meta_description'] ?? '')."\n"
            .'- body (text): '.mb_substr($bodyText, 0, $excerptChars)."\n\n"
            ."PRODUCE\n"
            ."1. answer: a direct answer to the search question in 2–4 sentences (<= {$answerMax} characters), the first sentence answering it outright. Plain prose, no headings, no bullet points, no HTML.\n"
            ."2. faq: up to {$faqMax} question/answer pairs for the OTHER phrasings and the natural follow-ups a homeowner asks next; each answer <= {$faqAnswerMax} characters, plain prose, no HTML. Omit a pair that would repeat the direct answer.\n"
            ."3. title: the post's H1, rewritten ONLY if the current one does not plainly match the search question; else return it unchanged. <= {$titleMax} characters.\n"
            ."4. seo_title: <= {$titleMax} characters, leading with the question's subject; never the bare question.\n"
            ."5. meta_description: <= {$metaMax} characters, answering the question in one line.\n"
            .'Return ONLY a JSON object: {"answer": "...", "faq": [{"question": "...", "answer": "..."}], "title": "...", "seo_title": "...", "meta_description": "..."}';

        $result = $this->claude->completeDetailed($prompt, $system);
        $decoded = json_decode($this->extractJson($result->text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(DraftFailure::emptyResponse($result->text, $result)->summary());
        }

        $answer = $this->clean((string) ($decoded['answer'] ?? ''), $answerMax);
        if ($answer === '') {
            throw new RuntimeException('The refresh call returned no direct answer — nothing was changed.');
        }

        $faq = [];
        foreach (is_array($decoded['faq'] ?? null) ? $decoded['faq'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $q = $this->clean((string) ($item['question'] ?? ''), 200);
            $a = $this->clean((string) ($item['answer'] ?? ''), $faqAnswerMax);
            if ($q !== '' && $a !== '' && mb_strtolower(rtrim($q, '?')) !== mb_strtolower(rtrim($question, '?'))) {
                $faq[] = ['question' => rtrim($q, '?').'?', 'answer' => $a];
            }
            if (count($faq) >= $faqMax) {
                break;
            }
        }

        return [
            'answer' => $answer,
            'faq' => $faq,
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
     * The proposal, field by field: the model's value when it is non-empty and within budget, else the
     * current one (a blank or over-long field never blanks a live title).
     *
     * @param  array{answer: string, faq: list<array{question: string, answer: string}>, title: string, seo_title: string, meta_description: string}  $payload
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
     * The body with the lane's two blocks (re)placed: the direct answer first, the original body (minus
     * any earlier lane blocks) untouched, the Q&A section last.
     *
     * @param  list<array{question: string, answer: string}>  $faq
     */
    public function rebuildBody(string $body, string $question, string $answer, array $faq): string
    {
        $core = trim($this->withoutLaneBlocks($body));
        $q = rtrim(trim($question), '?');
        $lead = '<div class="'.self::ANSWER_CLASS.'"><p><strong>'.e(ucfirst($q)).'?</strong> '.e($answer).'</p></div>';

        $tail = '';
        if ($faq !== []) {
            $tail = '<section class="'.self::FAQ_CLASS.'"><h2>Questions homeowners ask</h2>';
            foreach ($faq as $item) {
                $tail .= '<h3>'.e($item['question']).'</h3><p>'.e($item['answer']).'</p>';
            }
            $tail .= '</section>';
        }

        return $lead."\n".$core.($tail !== '' ? "\n".$tail : '');
    }

    /** The body with any earlier lane blocks removed (idempotent re-runs). */
    public function withoutLaneBlocks(string $body): string
    {
        $body = (string) preg_replace('/<div class="'.preg_quote(self::ANSWER_CLASS, '/').'">.*?<\/div>\s*/s', '', $body);

        return (string) preg_replace('/\s*<section class="'.preg_quote(self::FAQ_CLASS, '/').'">.*?<\/section>/s', '', $body);
    }

    /**
     * The faq slot the plugin's FAQPage schema reads: whatever was there that the lane did not write,
     * plus the lane's new pairs (the lane's earlier pairs, if any, are replaced).
     *
     * @param  list<array{question: string, answer: string}>  $fresh
     * @return list<array{question: string, answer: string}>
     */
    private function mergeFaqSlot(mixed $existing, mixed $previousLane, array $fresh): array
    {
        $previousQuestions = [];
        foreach (is_array($previousLane) ? $previousLane : [] as $item) {
            if (is_array($item) && isset($item['question'])) {
                $previousQuestions[mb_strtolower((string) $item['question'])] = true;
            }
        }
        $kept = [];
        foreach (is_array($existing) ? $existing : [] as $item) {
            if (is_array($item) && isset($item['question'], $item['answer']) && ! isset($previousQuestions[mb_strtolower((string) $item['question'])])) {
                $kept[] = ['question' => (string) $item['question'], 'answer' => (string) $item['answer']];
            }
        }

        return [...$kept, ...$fresh];
    }

    private function clean(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));

        return mb_strlen($value) > $max ? '' : $value;
    }

    private function extractJson(string $raw): string
    {
        $raw = trim($raw);
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        return $start !== false && $end !== false && $end >= $start ? substr($raw, $start, $end - $start + 1) : '{}';
    }
}
