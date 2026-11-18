<?php

namespace App\Social\Provider;

use App\Enums\SocialNetwork;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Models\SocialPost;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The Upload-Post transport (§ Social): one Upload-Post PROFILE per tenant (`Site.social_profile`), the
 * agency's Google Business / Facebook / Instagram accounts connected to it in the Upload-Post dashboard.
 *
 * `profiles()` reads the profile's connected accounts, and for Google Business every location the
 * connection can post to — each location is its own channel, matched to an office by {@see ChannelSync}
 * (Upload-Post exposes only the location's title, so the match runs on the name). `publish()` sends the
 * finished post: a photo post when the share carries an image, a text post otherwise, with the GBP
 * location pinned per call and the share link as the "Learn more" button. Publishing is synchronous
 * where Upload-Post allows it; a request it turns async is polled for its result.
 *
 * Never composes, never routes, never decides — it only moves one finished post to one channel.
 */
final class UploadPostSocialProvider implements SocialProvider
{
    /** Upload-Post's platform keys for our networks. */
    private const PLATFORM = [SocialNetwork::Gbp->value => 'google_business', SocialNetwork::Facebook->value => 'facebook', SocialNetwork::Instagram->value => 'instagram'];

    private const POLL_ATTEMPTS = 12;

    public function __construct(
        private readonly Http $http,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.upload-post.com',
        private readonly int $timeout = 60,
        /** Seconds between status polls; 0 in tests. */
        private readonly int $pollSeconds = 5,
    ) {}

    public static function configured(): bool
    {
        return trim((string) config('services.upload_post.api_key', '')) !== '';
    }

    public function profiles(Site $site): array
    {
        $profile = self::profileFor($site);
        if ($profile === null) {
            return [];
        }

        $json = $this->json($this->request()->get($this->url('/api/uploadposts/users/'.rawurlencode($profile))));
        $accounts = $json['profile']['social_accounts'] ?? $json['social_accounts'] ?? ($json['profiles'][0]['social_accounts'] ?? []);
        $accounts = is_array($accounts) ? $accounts : [];

        $out = [];
        foreach ([SocialNetwork::Facebook, SocialNetwork::Instagram] as $network) {
            $account = $accounts[self::PLATFORM[$network->value]] ?? null;
            if (! is_array($account) || $account === []) {
                continue;   // not connected (Upload-Post sends '' or null for an unconnected network)
            }
            $id = trim((string) ($account['username'] ?? $account['handle'] ?? ''));
            $name = trim((string) ($account['display_name'] ?? $account['handle'] ?? $id));
            if ($id === '' && $name === '') {
                continue;
            }
            $out[] = new SocialProfile($id !== '' ? $id : $name, $network, $name !== '' ? $name : $id);
        }

        $gbp = $accounts['google_business'] ?? null;
        if (is_array($gbp) && $gbp !== []) {
            foreach ($this->gbpLocations($profile) as $location) {
                $out[] = new SocialProfile((string) $location['name'], SocialNetwork::Gbp, (string) $location['title']);
            }
        }

        return $out;
    }

    public function publish(SocialChannel $channel, SocialPost $post): SocialPublishResult
    {
        $site = Site::withoutGlobalScope(SiteScope::class)->find($channel->site_id);
        $profile = $site === null ? null : self::profileFor($site);
        if ($profile === null) {
            throw new SocialProviderException('No Upload-Post profile is set for this site (Connections → Set social posting profile).');
        }
        $platform = self::PLATFORM[$post->network->value] ?? null;
        if ($platform === null) {
            throw new SocialProviderException("Upload-Post has no platform for {$post->network->value}.");
        }

        $fields = [
            ['name' => 'user', 'contents' => $profile],
            ['name' => 'platform[]', 'contents' => $platform],
            ['name' => 'title', 'contents' => $this->caption($post)],
            ['name' => 'async_upload', 'contents' => 'false'],
            ['name' => 'request_id', 'contents' => 'lp-'.$post->id],
        ];
        if ($post->network === SocialNetwork::Gbp) {
            $fields[] = ['name' => 'gbp_location_id', 'contents' => (string) $channel->provider_channel_id];
            $fields[] = ['name' => 'gbp_topic_type', 'contents' => 'STANDARD'];
            if (trim((string) $post->link) !== '') {
                $fields[] = ['name' => 'gbp_cta_type', 'contents' => 'LEARN_MORE'];
                $fields[] = ['name' => 'gbp_cta_url', 'contents' => trim((string) $post->link)];
            }
        }
        if ($post->network === SocialNetwork::Facebook && trim((string) $post->link) !== '' && trim((string) $post->image_url) === '') {
            $fields[] = ['name' => 'link_url', 'contents' => trim((string) $post->link)];
        }

        $image = trim((string) $post->image_url);
        $endpoint = $image !== '' ? '/api/upload_photos' : '/api/upload_text';
        if ($image !== '') {
            $fields[] = ['name' => 'photos[]', 'contents' => $image];
        }

        $request = $this->request()->withHeaders(['Idempotency-Key' => 'lp-'.$post->id]);
        foreach ($fields as $f) {
            $request = $request->attach($f['name'], $f['contents']);
        }
        $json = $this->json($request->post($this->url($endpoint)));

        $result = $this->platformResult($json, $platform);
        if ($result === null && isset($json['request_id'])) {
            $result = $this->awaitResult((string) $json['request_id'], $platform);
        }
        if ($result === null) {
            throw new SocialProviderException('Upload-Post accepted the post but returned no result for '.$platform.'.');
        }
        if (($result['success'] ?? false) !== true || ! empty($result['error'])) {
            $why = (string) ($result['error'] ?? $result['message'] ?? $result['error_code'] ?? 'refused');

            throw new SocialProviderException("Upload-Post ({$platform}): {$why}");
        }

        $id = (string) ($result['post_id'] ?? $result['platform_post_id'] ?? ($result['post_ids'][0] ?? '') ?: ($json['request_id'] ?? 'lp-'.$post->id));
        $url = $result['url'] ?? $result['post_url'] ?? $result['permalink'] ?? null;

        return new SocialPublishResult($id, is_string($url) && $url !== '' ? $url : null);
    }

    /** The caption Upload-Post receives: the composed copy, with the hashtags the composer kept apart appended where the network uses them. */
    private function caption(SocialPost $post): string
    {
        $copy = trim((string) $post->copy);
        $tags = is_array($post->hashtags) ? array_values(array_filter(array_map('strval', $post->hashtags))) : [];
        if ($tags === [] || ! $post->network->usesHashtags()) {
            return $copy;
        }
        $line = implode(' ', array_map(fn (string $t): string => str_starts_with($t, '#') ? $t : '#'.$t, $tags));

        return str_contains($copy, $line) ? $copy : rtrim($copy)."\n\n".$line;
    }

    /**
     * @return list<array{name: string, title: string}>
     */
    private function gbpLocations(string $profile): array
    {
        $out = [];
        $cursor = null;
        for ($page = 0; $page < 10; $page++) {
            $query = ['profile' => $profile];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $json = $this->json($this->request()->get($this->url('/api/uploadposts/google-business/locations'), $query));
            foreach (($json['locations'] ?? []) as $loc) {
                if (! is_array($loc) || trim((string) ($loc['name'] ?? '')) === '') {
                    continue;
                }
                $out[] = ['name' => trim((string) $loc['name']), 'title' => trim((string) ($loc['title'] ?? $loc['name']))];
            }
            $cursor = isset($json['next_cursor']) && is_string($json['next_cursor']) && ($json['incomplete'] ?? false) ? $json['next_cursor'] : null;
            if ($cursor === null) {
                break;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null the per-platform entry of a sync response */
    private function platformResult(array $json, string $platform): ?array
    {
        $results = $json['results'] ?? null;
        if (is_array($results) && isset($results[$platform]) && is_array($results[$platform])) {
            return $results[$platform];
        }
        if (is_array($results) && array_is_list($results)) {
            foreach ($results as $r) {
                if (is_array($r) && ($r['platform'] ?? null) === $platform) {
                    return $r;
                }
            }
        }

        return null;
    }

    /** Poll the status endpoint for a request Upload-Post took async; the per-platform entry when it ends. */
    private function awaitResult(string $requestId, string $platform): ?array
    {
        for ($i = 0; $i < self::POLL_ATTEMPTS; $i++) {
            if ($i > 0 && $this->pollSeconds > 0) {
                sleep($this->pollSeconds);
            }
            $json = $this->json($this->request()->get($this->url('/api/uploadposts/status'), ['request_id' => $requestId]));
            $status = (string) ($json['status'] ?? '');
            $entry = $this->platformResult($json, $platform);
            if ($entry !== null && in_array((string) ($entry['status'] ?? ''), ['completed', 'failed', 'skipped'], true)) {
                return $entry + ['success' => ($entry['status'] === 'completed') && ($entry['success'] ?? true) !== false];
            }
            if ($status === 'failed') {
                return ['success' => false, 'error' => (string) ($json['message'] ?? 'upload failed')];
            }
            if ($status === 'completed') {
                return $entry ?? ['success' => true];
            }
            if ($status === 'not_found') {
                return ['success' => false, 'error' => 'Upload-Post lost the request ('.$requestId.')'];
            }
        }

        return ['success' => false, 'error' => 'Upload-Post did not finish the post in time ('.$requestId.')'];
    }

    public static function profileFor(Site $site): ?string
    {
        $p = trim((string) ($site->social_profile ?? ''));

        return $p !== '' ? $p : null;
    }

    private function request(): PendingRequest
    {
        return $this->http->baseUrl($this->baseUrl)
            ->withHeaders(['Authorization' => 'Apikey '.$this->apiKey, 'Accept' => 'application/json'])
            ->timeout($this->timeout)
            ->connectTimeout(10);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        try {
            $json = $response->json();
        } catch (Throwable) {
            $json = null;
        }
        if ($response->status() === 401) {
            throw new SocialProviderException('Upload-Post rejected the API key (401).');
        }
        if ($response->status() === 429) {
            $usage = is_array($json) && isset($json['usage']) ? ' (usage '.json_encode($json['usage']).')' : '';

            throw new SocialProviderException('Upload-Post limit reached: '.(is_array($json) ? (string) ($json['message'] ?? '429') : '429').$usage);
        }
        if (! $response->successful()) {
            $message = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';

            throw new SocialProviderException('Upload-Post HTTP '.$response->status().($message !== '' ? ': '.$message : ''));
        }
        if (! is_array($json)) {
            throw new SocialProviderException('Upload-Post returned a non-JSON body.');
        }
        if (($json['success'] ?? true) === false && ! isset($json['results'])) {
            throw new SocialProviderException('Upload-Post: '.(string) ($json['message'] ?? $json['error'] ?? 'refused'));
        }

        return $json;
    }
}
