<?php

namespace App\Filament\Pages\Gathering;

use App\Enums\ConnectionProvider;
use App\Filament\Resources\ConnectionsResource;
use App\Filament\Resources\SourceResource;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Source;

/**
 * New Setup · Step 6 — Connections & Feeds (operator-only, one page). The existing Connections and
 * Feeds surfaces are re-used wholesale rather than rebuilt: this step shows the site's honest
 * connection + feed state (WordPress present/verified/compromised, keys, enabled news sources)
 * and deep-links into the existing pages for the actual management flows.
 *
 * @property-read array{wp: array{present: bool, compromised: bool, provider_count: int}, feeds: array{total: int, enabled: int}} $summary
 */
class ConnectionsStep extends GatheringPage
{
    protected static ?string $slug = 'setup2/connections';

    protected static ?string $navigationLabel = 'Connections & Feeds';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.gathering.connections-step';

    /**
     * @return array{wp: array{present: bool, compromised: bool, provider_count: int}, feeds: array{total: int, enabled: int}}
     */
    public function getSummaryProperty(): array
    {
        if ($this->siteId === null) {
            return ['wp' => ['present' => false, 'compromised' => false, 'provider_count' => 0], 'feeds' => ['total' => 0, 'enabled' => 0]];
        }

        $connections = Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->get();
        $wp = $connections->first(fn (Connection $c) => $c->provider === ConnectionProvider::WpAppPassword);
        $sources = Source::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->get();

        return [
            'wp' => [
                'present' => $wp !== null,
                'compromised' => (bool) ($wp?->compromised ?? false),
                'provider_count' => $connections->count(),
            ],
            'feeds' => [
                'total' => $sources->count(),
                'enabled' => $sources->filter(fn (Source $s) => (bool) $s->enabled)->count(),
            ],
        ];
    }

    public function connectionsUrl(): string
    {
        return ConnectionsResource::getUrl('index');
    }

    public function feedsUrl(): string
    {
        return SourceResource::getUrl('index');
    }

    /** @return array{state: 'complete'|'attention'|'empty', label: string} */
    public function readiness(): array
    {
        $summary = $this->getSummaryProperty();

        if ($summary['wp']['present'] && ! $summary['wp']['compromised'] && $summary['feeds']['enabled'] > 0) {
            return ['state' => 'complete', 'label' => 'Complete'];
        }

        if (! $summary['wp']['present'] && $summary['feeds']['total'] === 0) {
            return ['state' => 'empty', 'label' => 'Nothing connected yet'];
        }

        $missing = [];
        if (! $summary['wp']['present']) {
            $missing[] = 'WordPress not connected';
        } elseif ($summary['wp']['compromised']) {
            $missing[] = 'WordPress credential flagged';
        }
        if ($summary['feeds']['enabled'] === 0) {
            $missing[] = 'no enabled feeds';
        }

        return ['state' => 'attention', 'label' => implode(' · ', $missing)];
    }
}
