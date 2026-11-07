<?php

namespace App\Social;

use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SocialChannel;
use App\Social\Provider\SocialProfile;
use App\Social\Provider\SocialProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Channels are whatever the posting provider has connected — read from it, never typed in. Each profile
 * becomes (or updates) a {@see SocialChannel}; a GBP profile is tied to one of our physical locations by
 * place id, else by address, else by name (location name or its city in the profile name). A GBP profile
 * nothing matches stays `unmatched` (location null) and is listed on the board — it never takes a job.
 * A profile the provider no longer lists is disabled, its share history kept.
 */
final class ChannelSync
{
    /** Words a location name carries that say nothing about which location it is. */
    private const GENERIC = ['office', 'branch', 'hq', 'headquarters', 'location', 'shop', 'store', 'the', 'and', 'of', 'main', 'nj', 'ny', 'pa'];

    public function __construct(private readonly SocialProvider $provider) {}

    /** @return array{synced: int, matched: int, unmatched: list<string>, disabled: int} */
    public function sync(Site $site): array
    {
        $profiles = $this->provider->profiles($site);
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereNull('merged_into_id')->get();
        $existing = SocialChannel::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy(fn (SocialChannel $c): string => $c->network->value.'|'.$c->provider_channel_id);
        $seen = [];
        $out = ['synced' => 0, 'matched' => 0, 'unmatched' => [], 'disabled' => 0];
        $now = Carbon::now();

        foreach ($profiles as $profile) {
            $key = $profile->network->value.'|'.$profile->id;
            $seen[$key] = true;
            $location = $profile->network->perLocation() ? $this->match($profile, $locations) : null;
            $attrs = [
                'provider_name' => $profile->name,
                'provider_address' => $profile->address,
                'provider_place_id' => $profile->placeId,
                'location_id' => $location?->id,
                'enabled' => true,
                'synced_at' => $now,
            ];
            $channel = $existing->get($key);
            if ($channel === null) {
                SocialChannel::withoutGlobalScope(SiteScope::class)->create([...$attrs, 'site_id' => $site->id, 'network' => $profile->network, 'provider_channel_id' => $profile->id]);
            } else {
                $channel->forceFill($attrs)->save();
            }
            $out['synced']++;
            if ($profile->network->perLocation()) {
                if ($location !== null) {
                    $out['matched']++;
                } else {
                    $out['unmatched'][] = $profile->name;
                }
            }
        }

        foreach ($existing as $key => $channel) {
            if (! isset($seen[$key]) && $channel->enabled) {
                $channel->forceFill(['enabled' => false, 'synced_at' => $now])->save();
                $out['disabled']++;
            }
        }

        return $out;
    }

    /** @param  Collection<int, Location>  $locations */
    private function match(SocialProfile $profile, Collection $locations): ?Location
    {
        if ($profile->placeId !== null && $profile->placeId !== '') {
            $hit = $locations->first(fn (Location $l): bool => (string) $l->place_id === $profile->placeId);
            if ($hit !== null) {
                return $hit;
            }
        }
        $address = self::normalize((string) $profile->address);
        if ($address !== '') {
            $hit = $locations->first(fn (Location $l): bool => self::normalize((string) $l->address) !== '' && str_starts_with($address, self::streetKey((string) $l->address)));
            if ($hit !== null) {
                return $hit;
            }
        }
        $name = self::normalize($profile->name);
        if ($name !== '') {
            // The location's distinctive words ("Hackettstown office" → hackettstown) all in the profile name.
            $hit = $locations->first(function (Location $l) use ($name): bool {
                $words = array_values(array_diff(explode(' ', self::normalize((string) $l->name)), self::GENERIC));
                if ($words === []) {
                    return false;
                }
                foreach ($words as $w) {
                    if (! str_contains(' '.$name.' ', ' '.$w.' ')) {
                        return false;
                    }
                }

                return true;
            });
            if ($hit !== null) {
                return $hit;
            }
            $hit = $locations->first(function (Location $l) use ($name): bool {
                $city = self::normalize($l->cityState()['city']);

                return $city !== '' && str_contains($name, $city);
            });
            if ($hit !== null) {
                return $hit;
            }
        }

        return $locations->count() === 1 ? $locations->first() : null;
    }

    private static function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower((string) preg_replace('/[^A-Za-z0-9 ]+/', ' ', $value))));
    }

    /** The street number + first street word of an address ("400 memorial") — what two renderings of one address agree on. */
    private static function streetKey(string $address): string
    {
        $n = self::normalize($address);
        $parts = explode(' ', $n);

        return implode(' ', array_slice($parts, 0, min(2, count($parts))));
    }
}
