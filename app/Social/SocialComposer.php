<?php

namespace App\Social;

use App\Enums\RenderStatus;
use App\Enums\SocialNetwork;
use App\Enums\SocialTrigger;
use App\JobCapture\Photos\PhotoUrl;
use App\Models\Content;
use App\Models\Job;
use App\Support\PublicUrl;

/**
 * Composes a share deterministically from what the engine already wrote — no model call. A job becomes
 * "service in town" + the first lines of its enhanced write-up + its primary photo + a Learn-more link to
 * the job page; a post becomes its title + the direct answer (a question post) or its meta description +
 * its hero image + the link. Hashtags come from the service and the town (jobs) or the silo (posts);
 * GBP gets none (they render as plain text there and do nothing). Copy is cut to the network's cap at a
 * sentence boundary, never mid-word, and the link always survives the cut.
 */
final class SocialComposer
{
    public function job(Job $job, SocialNetwork $network, SocialTrigger $trigger): SocialCopy
    {
        $site = $job->site;
        $city = $job->job_city_id !== null ? $job->city : null;
        $service = $this->jobService($job);
        $town = $city?->name;
        $title = trim((string) $job->post_title) ?: trim(implode(' in ', array_filter([$service, $town])));
        $excerpt = $this->excerpt((string) ($job->enhanced_description ?? $job->source_description ?? ''), (int) config('launchpad.social.excerpt_chars', 320));
        $rawLink = $job->publicUrl($site?->domain_url);
        $link = $rawLink !== null ? ShareLink::tag($rawLink, $network, $job->publicSlug(), $trigger->value) : null;
        $hashtags = $network->usesHashtags()
            ? array_values(array_unique(array_filter([$service !== null ? Hashtags::one($service) : null, Hashtags::town($town, $city?->state)])))
            : [];
        $brand = trim((string) $site?->brand_name);
        $state = trim((string) $city?->state);
        $lines = array_filter([
            $title,
            $excerpt,
            $town !== null && $brand !== '' ? "{$brand} serving {$town}".($state !== '' ? ', '.$state : '').'.' : null,
        ]);
        $photo = $this->jobPhoto($job);

        return $this->assemble(implode("\n\n", $lines), $hashtags, $photo, $link, $network, 'See the job');
    }

    public function post(Content $post, SocialNetwork $network, SocialTrigger $trigger): SocialCopy
    {
        $site = $post->site;
        $meta = is_array($post->meta) ? $post->meta : [];
        $mark = is_array($meta['question_refresh'] ?? null) ? $meta['question_refresh'] : [];
        $answer = trim((string) ($mark['answer'] ?? ''));
        $seo = is_array($meta['seo'] ?? null) ? $meta['seo'] : [];
        $description = trim((string) ($seo['meta_description'] ?? $meta['description'] ?? ''));
        $question = trim((string) ($mark['question'] ?? ''));
        $lead = $answer !== '' ? $answer : ($description !== '' ? $description : $this->excerpt((string) $post->body, (int) config('launchpad.social.excerpt_chars', 320)));
        $title = trim((string) $post->title);
        $rawLink = PublicUrl::forContent($site?->domain_url, $post);
        $link = $rawLink !== null ? ShareLink::tag($rawLink, $network, (string) $post->slug, $trigger->value) : null;
        $hashtags = $network->usesHashtags() ? Hashtags::from([$post->silo?->name, $site?->brand_name]) : [];
        $lines = array_filter([
            $question !== '' && strcasecmp($question, $title) !== 0 ? ucfirst($question).'?' : null,
            $title,
            $lead,
        ]);
        // A question's own phrasing leads when we have one ("how much does a sump pump cost?"), then the
        // title, then the direct answer — the answer is the value, the link is the click.
        if ($question !== '') {
            $lines = array_filter([ucfirst(rtrim($question, '?')).'?', $lead, $title !== '' ? "Full answer: {$title}" : null]);
        }

        return $this->assemble(implode("\n\n", $lines), $hashtags, $this->postImage($post), $link, $network, 'Read the answer');
    }

    /**
     * The GBP Q&A pair for a question post — the mined question as the owner-posted question, a short
     * answer + the link as the owner's answer. Copied by hand from the Shares panel until the Q&A API lands.
     *
     * @return array{question: string, answer: string}|null
     */
    public function gbpQa(Content $post): ?array
    {
        $meta = is_array($post->meta) ? $post->meta : [];
        $mark = is_array($meta['question_refresh'] ?? null) ? $meta['question_refresh'] : [];
        $question = trim((string) ($mark['question'] ?? ''));
        $answer = trim((string) ($mark['answer'] ?? ''));
        if ($question === '' || $answer === '') {
            return null;
        }
        $link = PublicUrl::forContent($post->site?->domain_url, $post);
        $link = $link !== null ? ShareLink::tag($link, SocialNetwork::Gbp, (string) $post->slug, 'qa') : null;

        return [
            'question' => ucfirst(rtrim($question, '?')).'?',
            'answer' => $this->cut($answer, 900, '').($link !== null ? "\n\nMore: {$link}" : ''),
        ];
    }

    /** @param  list<string>  $hashtags */
    private function assemble(string $body, array $hashtags, ?string $image, ?string $link, SocialNetwork $network, string $ctaLabel): SocialCopy
    {
        $tail = '';
        if ($link !== null && $network->linkInCopy()) {
            $tail .= "\n\n{$ctaLabel}: {$link}";
        }
        if ($hashtags !== []) {
            $tail .= "\n\n".implode(' ', $hashtags);
        }
        $room = max(80, $network->maxChars() - mb_strlen($tail));
        $copy = $this->cut(trim($body), $room, '…').$tail;

        return new SocialCopy($copy, $hashtags, $image, $link, $link !== null ? 'LEARN_MORE' : null);
    }

    /** Cut at the last sentence end inside the cap; failing that the last word; the ellipsis marks a cut. */
    private function cut(string $text, int $max, string $ellipsis): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $slice = mb_substr($text, 0, $max - mb_strlen($ellipsis));
        $sentence = max(mb_strrpos($slice, '. ') ?: 0, mb_strrpos($slice, ".\n") ?: 0, mb_strrpos($slice, '! ') ?: 0, mb_strrpos($slice, '? ') ?: 0);
        if ($sentence > $max * 0.4) {
            return rtrim(mb_substr($slice, 0, $sentence + 1));
        }
        $space = mb_strrpos($slice, ' ');

        return rtrim($space !== false ? mb_substr($slice, 0, $space) : $slice).$ellipsis;
    }

    /** Plain text, first sentences up to the cap. */
    private function excerpt(string $html, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('/<\/(p|h[1-6]|li|div)>/i', ' ', $html) ?? ''), ENT_QUOTES | ENT_HTML5)));

        return $this->cut($text, $max, '…');
    }

    private function jobService(Job $job): ?string
    {
        $label = $job->jobTypes->first()?->label;

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    private function jobPhoto(Job $job): ?string
    {
        $photos = is_array($job->photos) ? $job->photos : [];
        $photo = $photos[$job->primary_photo_index] ?? $photos[0] ?? null;
        $key = is_array($photo) ? $photo['r2_key'] : '';
        $url = $key !== '' ? PhotoUrl::for($key) : '';

        return $url !== '' ? $url : null;
    }

    private function postImage(Content $post): ?string
    {
        foreach ($post->renderJobs as $render) {
            if ($render->status === RenderStatus::Succeeded) {
                $object = $render->toImageObject();
                if (is_array($object) && isset($object['url'])) {
                    return (string) $object['url'];
                }
            }
        }

        return null;
    }
}
