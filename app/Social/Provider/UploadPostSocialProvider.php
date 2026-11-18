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
 * The Upload-Post transport (§ Social): one Upload-Post PROFILE per tenant — or several, comma-separated in
 * `Site.social_profile`, when its listings sit under more than one Google account — the
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
        $out = [];
        $seenIds = [];
        $seenPlaces = [];
        foreach (self::profilesFor($site) as $profile) {
            foreach ($this->profileChannels($profile) as $channel) {
                // The same channel can show under two profiles (two Google accounts over one listing, one
                // Page connected twice): one channel, through the first profile that exposed it. A GBP seen
                // again under another listing id but the same place id is the same storefront — kept once,
                // preferring the title that names the town.
                $key = $channel->network->value.'|'.$channel->id;
                if (isset($seenIds[$key])) {
                    continue;
                }
                if ($channel->network === SocialNetwork::Gbp && $channel->placeId !== null) {
                    $placeKey = $channel->placeId;
                    if (isset($seenPlaces[$placeKey])) {
                        $kept = $out[$seenPlaces[$placeKey]];
                        if (! str_contains($kept->name, '|') && str_contains($channel->name, '|')) {
                            $out[$seenPlaces[$placeKey]] = $channel;
                        }

                        continue;
                    }
                    $seenPlaces[$placeKey] = count($out);
                }
                $seenIds[$key] = true;
                $out[] = $channel;
            }
        }

        return array_values($out);
    }

    /**
     * Every profile ("user") in the agency's Upload-Post account, with what is connected on each. One read;
     * the per-platform entries are an object when connected and an empty string when not.
     */
    public function accountProfiles(): array
    {
        $json = $this->json($this->send(fn () => $this->request()->get($this->url('/api/uploadposts/users'))));
        $records = is_array($json['profiles'] ?? null) ? $json['profiles'] : (is_array($json['users'] ?? null) ? $json['users'] : []);

        $out = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $name = trim((string) ($record['username'] ?? $record['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = new AccountProfile($name, self::connectedOn($record));
        }
        usort($out, fn (AccountProfile $a, AccountProfile $b): int => strcasecmp($a->name, $b->name));

        return $out;
    }

    /**
     * What one profile record has signed in, per network: the Page name (profile-level, like publish() reads
     * it), the Instagram handle, the Google account's display name.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    private static function connectedOn(array $record): array
    {
        $accounts = is_array($record['social_accounts'] ?? null) ? $record['social_accounts'] : [];
        $label = static function (mixed $entry, string ...$keys): ?string {
            if (! is_array($entry) || $entry === []) {
                return null;
            }
            foreach ($keys as $key) {
                $raw = $entry[$key] ?? null;
                $v = is_scalar($raw) ? trim((string) $raw) : '';
                if ($v !== '') {
                    return $v;
                }
            }

            return '';
        };

        $out = [];
        $fb = $label($accounts['facebook'] ?? null, 'display_name', 'handle', 'username');
        if ($fb !== null) {
            $page = trim((string) ($record['facebook_page_name'] ?? ''));
            $out[SocialNetwork::Facebook->value] = $page !== '' ? $page : $fb;
        }
        $ig = $label($accounts['instagram'] ?? null, 'username', 'handle', 'display_name');
        if ($ig !== null) {
            $out[SocialNetwork::Instagram->value] = $ig;
        }
        $gbp = $label($accounts['google_business'] ?? null, 'display_name', 'handle', 'username');
        if ($gbp !== null) {
            $out[SocialNetwork::Gbp->value] = $gbp;
        }

        return $out;
    }

    /**
     * The channels one Upload-Post profile exposes: its Page, its Instagram account, and every Google
     * Business location its Google connection can post to.
     *
     * @return list<SocialProfile>
     */
    private function profileChannels(string $profile): array
    {
        $json = $this->json($this->send(fn () => $this->request()->get($this->url('/api/uploadposts/users/'.rawurlencode($profile)))));
        $record = is_array($json['profile'] ?? null) ? $json['profile'] : (is_array($json['profiles'][0] ?? null) ? $json['profiles'][0] : $json);
        $accounts = is_array($record['social_accounts'] ?? null) ? $record['social_accounts'] : [];

        $out = [];
        // Facebook: the PAGE the profile publishes to is a profile-level field (the account entry is the
        // person who connected it, not the Page); the Page id is what publish() sends as facebook_page_id.
        $fb = $accounts['facebook'] ?? null;
        if (is_array($fb) && $fb !== []) {
            $pageId = trim((string) ($record['facebook_page_id'] ?? ''));
            $pageName = trim((string) ($record['facebook_page_name'] ?? ''));
            $id = $pageId !== '' ? $pageId : trim((string) ($fb['username'] ?? $fb['handle'] ?? ''));
            $name = $pageName !== '' ? $pageName : trim((string) ($fb['display_name'] ?? $fb['handle'] ?? $id));
            if ($id !== '') {
                $out[] = new SocialProfile($id, SocialNetwork::Facebook, $name !== '' ? $name : $id, account: $profile);
            }
        }
        $ig = $accounts['instagram'] ?? null;
        if (is_array($ig) && $ig !== []) {
            $id = trim((string) ($ig['username'] ?? $ig['handle'] ?? ''));
            $name = trim((string) ($ig['display_name'] ?? $ig['handle'] ?? $id));
            if ($id !== '' || $name !== '') {
                $out[] = new SocialProfile($id !== '' ? $id : $name, SocialNetwork::Instagram, $name !== '' ? $name : $id, account: $profile);
            }
        }

        $gbp = $accounts['google_business'] ?? null;
        if (is_array($gbp) && $gbp !== []) {
            foreach ($this->gbpLocations($profile) as $location) {
                // The listing's address and Google place id (one read per location) let ChannelSync tie it to
                // an office the sure way — place id, then street — so a listing named without its town still
                // lands on the right office. A read that fails leaves the name match as the fallback.
                $details = $this->gbpDetails($profile, (string) $location['name']);
                $out[] = new SocialProfile((string) $location['name'], SocialNetwork::Gbp, (string) $location['title'], $details['address'], $details['place_id'], account: $profile);
            }
        }

        return $out;
    }

    public function publish(SocialChannel $channel, SocialPost $post): SocialPublishResult
    {
        $site = Site::withoutGlobalScope(SiteScope::class)->find($channel->site_id);
        $profile = trim((string) $channel->provider_account);
        if ($profile === '') {
            $profile = $site === null ? null : (self::profilesFor($site)[0] ?? null);
        }
        if ($profile === null) {
            throw new SocialProviderException('No Upload-Post profile is set for this site (Connections → Set social posting profile).');
        }
        $platform = self::PLATFORM[$post->network->value];

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
        if ($post->network === SocialNetwork::Facebook) {
            if (ctype_digit((string) $channel->provider_channel_id)) {
                $fields[] = ['name' => 'facebook_page_id', 'contents' => (string) $channel->provider_channel_id];
            }
            if (trim((string) $post->link) !== '' && trim((string) $post->image_url) === '') {
                $fields[] = ['name' => 'link_url', 'contents' => trim((string) $post->link)];
            }
        }

        $image = trim((string) $post->image_url);
        $endpoint = $image !== '' ? '/api/upload_photos' : '/api/upload_text';
        if ($image !== '') {
            $fields[] = ['name' => 'photos[]', 'contents' => $image];
            if ($post->network === SocialNetwork::Gbp && $post->subject_type === 'job') {
                // A real job photo also goes to the location's gallery — photo count is a pack signal.
                $fields[] = ['name' => 'gbp_upload_to_gallery', 'contents' => 'true'];
                $fields[] = ['name' => 'gbp_media_category', 'contents' => 'AT_WORK'];
            }
        }

        $request = $this->request()->withHeaders(['Idempotency-Key' => 'lp-'.$post->id]);
        foreach ($fields as $f) {
            $request = $request->attach($f['name'], $f['contents']);
        }
        $json = $this->json($this->send(fn () => $request->post($this->url($endpoint))));

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
            $json = $this->json($this->send(fn () => $this->request()->get($this->url('/api/uploadposts/google-business/locations'), $query)));
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

    /**
     * A GBP location's street address and Google place id, as Google holds them.
     *
     * @return array{address: ?string, place_id: ?string}
     */
    private function gbpDetails(string $profile, string $locationName): array
    {
        try {
            $json = $this->json($this->send(fn () => $this->request()->get($this->url('/api/uploadposts/google-business/location-info'), [
                'user' => $profile, 'location_id' => $locationName, 'read_mask' => 'name,title,storefrontAddress,metadata',
            ])));
        } catch (SocialProviderException) {
            return ['address' => null, 'place_id' => null];
        }
        $info = is_array($json['info'] ?? null) ? $json['info'] : [];
        $addr = is_array($info['address'] ?? null) ? $info['address'] : [];
        $lines = array_values(array_filter(array_map('strval', is_array($addr['addressLines'] ?? null) ? $addr['addressLines'] : [])));
        $parts = array_filter([implode(' ', $lines), (string) ($addr['locality'] ?? ''), trim((string) ($addr['administrativeArea'] ?? '').' '.(string) ($addr['postalCode'] ?? ''))]);
        $address = trim(implode(', ', $parts));
        $placeId = trim((string) ($info['place_id'] ?? ($json['location']['metadata']['placeId'] ?? '')));

        return ['address' => $address !== '' ? $address : null, 'place_id' => $placeId !== '' ? $placeId : null];
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

    /**
     * Poll the status endpoint for a request Upload-Post took async; the per-platform entry when it ends.
     *
     * @return array<string, mixed>
     */
    private function awaitResult(string $requestId, string $platform): array
    {
        for ($i = 0; $i < self::POLL_ATTEMPTS; $i++) {
            if ($i > 0 && $this->pollSeconds > 0) {
                sleep($this->pollSeconds);
            }
            $json = $this->json($this->send(fn () => $this->request()->get($this->url('/api/uploadposts/status'), ['request_id' => $requestId])));
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

    /**
     * The tenant's Upload-Post profile usernames — one, or several comma-separated when its listings sit
     * under more than one Google account (each Google connection lives in its own Upload-Post profile).
     *
     * @return list<string>
     */
    public static function profilesFor(Site $site): array
    {
        $names = preg_split('/[\s,;]+/', trim((string) ($site->social_profile ?? ''))) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $names), fn (string $n): bool => $n !== '')));
    }

    public static function profileFor(Site $site): ?string
    {
        return self::profilesFor($site)[0] ?? null;
    }

    /**
     * Run one HTTP call; a transport failure (timeout, connection refused) is a TRANSIENT provider failure.
     *
     * @param  callable(): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (SocialProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SocialProviderException('Upload-Post unreachable: '.$e->getMessage(), transient: true);
        }
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
            $message = is_array($json) ? (string) ($json['message'] ?? '429') : '429';
            $usage = is_array($json) && isset($json['usage']) ? ' (usage '.json_encode($json['usage']).')' : '';

            // The monthly allowance is a refusal; a per-minute rate limit clears on its own.
            throw new SocialProviderException('Upload-Post limit reached: '.$message.$usage, transient: ! str_contains(strtolower($message), 'monthly'));
        }
        if (! $response->successful()) {
            $message = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';

            throw new SocialProviderException('Upload-Post HTTP '.$response->status().($message !== '' ? ': '.$message : ''), transient: $response->status() >= 500);
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
