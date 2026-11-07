<?php

namespace App\Social\Provider;

use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;

/**
 * The default provider: records every publish and answers with a deterministic id, so the whole
 * pipeline runs end to end (and in tests) with no network. `failNext()` makes the next call throw.
 */
final class MockSocialProvider implements SocialProvider
{
    /** @var list<array{channel: string, post: string, network: string, copy: string, image_url: string|null, link: string|null}> */
    public array $published = [];

    /** @var array<string, list<SocialProfile>> site id → profiles the mock answers with (tests seed it) */
    public array $profiles = [];

    private ?string $failWith = null;

    /** @param  list<SocialProfile>  $profiles */
    public function connect(string $siteId, array $profiles): void
    {
        $this->profiles[$siteId] = $profiles;
    }

    public function profiles(Site $site): array
    {
        return $this->profiles[(string) $site->id] ?? [];
    }

    public function failNext(string $message = 'provider refused the post'): void
    {
        $this->failWith = $message;
    }

    public function publish(SocialChannel $channel, SocialPost $post): SocialPublishResult
    {
        if ($this->failWith !== null) {
            $message = $this->failWith;
            $this->failWith = null;
            throw new SocialProviderException($message);
        }

        $this->published[] = [
            'channel' => (string) $channel->id,
            'post' => (string) $post->id,
            'network' => $post->network->value,
            'copy' => $post->copy,
            'image_url' => $post->image_url,
            'link' => $post->link,
        ];

        return new SocialPublishResult('mock-'.$post->id, 'https://social.example/'.$post->network->value.'/'.$post->id);
    }
}
