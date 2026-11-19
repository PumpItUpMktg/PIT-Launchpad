<?php

namespace App\Social;

use App\Models\Site;
use App\Social\Provider\AccountProfile;
use App\Social\Provider\SocialProvider;
use App\Social\Provider\SocialProviderException;
use App\Social\Provider\UploadPostSocialProvider;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What a Super Admin picks a tenant's posting profiles from: every profile in the agency's provider
 * account, with what is connected on each, and which other tenant already owns it. One provider account
 * serves every tenant; the segmentation is the pick — a profile belongs to one site, a site posts and syncs
 * only through its own. Read-only; the Connections action saves the pick.
 */
final class ProfilePicker
{
    private const CACHE_KEY = 'social.account_profiles';

    /** A day: the sync job (Sync now, the daily sync) re-reads it; the Shares page only ever reads the cache. */
    private const CACHE_SECONDS = 86400;

    public function __construct(private readonly SocialProvider $provider) {}

    /**
     * The picker for one site.
     *
     * @return array{
     *     options: array<string, string>,
     *     descriptions: array<string, string>,
     *     taken: array<string, string>,
     *     current: list<string>,
     *     error: string|null,
     * } options/descriptions keyed by profile name; `taken` = profile → the OTHER site's brand that owns it;
     *   `error` set (and options empty) when the provider could not be read
     */
    public function forSite(Site $site): array
    {
        $current = UploadPostSocialProvider::profilesFor($site);
        $taken = $this->takenBy($site);

        try {
            $profiles = $this->accountProfiles();
        } catch (Throwable $e) {
            return ['options' => [], 'descriptions' => [], 'taken' => $taken, 'current' => $current, 'error' => $e->getMessage()];
        }

        $options = [];
        $descriptions = [];
        foreach ($profiles as $profile) {
            $options[$profile->name] = $profile->name;
            $descriptions[$profile->name] = $profile->summary().(isset($taken[$profile->name]) ? ' · used by '.$taken[$profile->name] : '');
        }
        // A profile the site still names but the account no longer has stays listed, so it can be unticked.
        foreach ($current as $name) {
            if (! isset($options[$name])) {
                $options[$name] = $name;
                $descriptions[$name] = 'not in the provider account any more';
            }
        }

        return ['options' => $options, 'descriptions' => $descriptions, 'taken' => $taken, 'current' => $current, 'error' => null];
    }

    /**
     * The site's own profiles with what is connected on each — the read-only panel on the Shares page. Reads
     * the CACHE only (the page re-renders on a poll; a provider call inside a web request is what tripped the
     * request limit): until a sync has warmed it, the names show without their connections.
     *
     * @return array{profiles: list<array{name: string, summary: string, found: bool}>, error: string|null, warm: bool}
     */
    public function forShares(Site $site): array
    {
        $names = UploadPostSocialProvider::profilesFor($site);
        if ($names === []) {
            return ['profiles' => [], 'error' => null, 'warm' => true];
        }
        $account = $this->cachedAccountProfiles();
        if ($account === null) {
            return ['profiles' => array_map(fn (string $n): array => ['name' => $n, 'summary' => '', 'found' => true], $names), 'error' => null, 'warm' => false];
        }
        $byName = [];
        foreach ($account as $profile) {
            $byName[$profile->name] = $profile;
        }
        $rows = [];
        foreach ($names as $name) {
            $profile = $byName[$name] ?? null;
            $rows[] = ['name' => $name, 'summary' => $profile?->summary() ?? 'not in the provider account', 'found' => $profile !== null];
        }

        return ['profiles' => $rows, 'error' => null, 'warm' => true];
    }

    /**
     * The account's profiles: the cache when warm, else one provider read that warms it (the Connections
     * modal — an interactive one-off; the Shares page never comes here).
     *
     * @return list<AccountProfile>
     *
     * @throws SocialProviderException
     */
    public function accountProfiles(): array
    {
        return $this->cachedAccountProfiles() ?? $this->warm();
    }

    /**
     * Read the provider and cache the result — the sync job calls this on the worker after every sync.
     *
     * @return list<AccountProfile>
     *
     * @throws SocialProviderException
     */
    public function warm(): array
    {
        $profiles = $this->provider->accountProfiles();
        Cache::put(self::CACHE_KEY, $profiles, self::CACHE_SECONDS);

        return $profiles;
    }

    /** @return list<AccountProfile>|null null when nothing is cached */
    public function cachedAccountProfiles(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (! is_array($cached)) {
            return null;
        }
        /** @var list<AccountProfile> $cached */
        $cached = array_values(array_filter($cached, fn (mixed $p): bool => $p instanceof AccountProfile));

        return $cached;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Profiles already named by another site: profile → that site's brand. The one-profile-one-tenant rule.
     *
     * @return array<string, string>
     */
    private function takenBy(Site $site): array
    {
        $taken = [];
        Site::withoutGlobalScopes()->whereNotNull('social_profile')->whereKeyNot($site->getKey())
            ->get(['id', 'brand_name', 'social_profile'])
            ->each(function (Site $other) use (&$taken): void {
                foreach (UploadPostSocialProvider::profilesFor($other) as $name) {
                    $taken[$name] ??= (string) $other->brand_name;
                }
            });

        return $taken;
    }

    /**
     * Normalise a saved pick: strings or an array, trimmed, deduped, and never a profile another site owns.
     *
     * @param  array<string, string>  $taken
     * @return list<string>
     */
    public static function normalise(mixed $picked, array $taken = []): array
    {
        $names = is_array($picked) ? $picked : (preg_split('/[\s,;]+/', trim((string) $picked)) ?: []);
        $names = array_values(array_unique(array_filter(array_map(fn (mixed $n): string => trim((string) $n), $names), fn (string $n): bool => $n !== '')));

        return array_values(array_filter($names, fn (string $n): bool => ! isset($taken[$n])));
    }
}
