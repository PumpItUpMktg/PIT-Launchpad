<x-lp.shell variant="board">
    <style>
        .lp-metrics { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
        .lp-metric { display:flex; flex-direction:column; gap:4px; padding:14px 16px; border:1px solid var(--line); border-radius:12px; background:#fff; }
        .lp-metric .mv { font-size:26px; font-weight:800; color:#0f172a; line-height:1.1; }
        .lp-metric .mv.muted { color:#94a3b8; font-weight:700; }
        .lp-metric .ml { font-size:13px; font-weight:700; color:#0f172a; }
        .lp-metric .ms { font-size:12px; color:#64748b; }
        .lp-manage { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:10px; }
        .lp-manage-item { display:flex; flex-direction:column; gap:2px; padding:12px 14px; border:1px solid var(--line); border-radius:10px; text-decoration:none; background:#fff; transition:border-color .12s, box-shadow .12s; }
        .lp-manage-item:hover { border-color:#f59e0b; box-shadow:0 2px 8px rgba(15,23,42,.06); }
        .lp-manage-label { font-size:14px; font-weight:700; color:#0f172a; }
        .lp-manage-desc { font-size:12px; color:#64748b; }
        .lp-manage-item .prov { font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#b45309; }
        @media (prefers-color-scheme: dark) {
            .lp-metric, .lp-manage-item { background:#151b24; }
            .lp-metric .mv, .lp-metric .ml, .lp-manage-label { color:#f1f5f9; }
        }
    </style>
    @php
        $site = $this->getSite();
        $m = $this->metrics;
    @endphp
    @unless ($site)
        <div class="lp-card">
            <x-lp.empty title="No tenant selected" action="Go to the Lobby" :href="\App\Filament\Pages\Lobby::getUrl()">
                Pick a site in the Lobby to work on — the dashboard renders for the locked tenant.
            </x-lp.empty>
        </div>
    @else
        <x-lp.page-header eyebrow="Dashboard" :title="$site->brand_name" :scope="false">
            <x-slot:meta>
                <x-lp.chip :for="$site->status" />
                @if ($m['data_through'])
                    <span style="font-size:12px;color:#64748b">Data through {{ $m['data_through'] }}</span>
                @endif
            </x-slot:meta>
        </x-lp.page-header>

        {{-- Metric cards — every value is a persisted read (metric spine + durable tables); no card
             makes a live provider call at render (acceptance 16). Each shows a "not measured yet" state
             until its sync has written data. --}}
        <div class="lp-metrics">
            {{-- PageSpeed / Core Web Vitals --}}
            <div class="lp-metric">
                @if ($m['pagespeed']['empty'] || $m['pagespeed']['value'] === null)
                    <div class="mv muted">—</div>
                @else
                    <div class="mv">{{ $m['pagespeed']['value'] }}</div>
                @endif
                <div class="ml">PageSpeed</div>
                <div class="ms">{{ $m['pagespeed']['empty'] ? 'Not measured yet' : $m['pagespeed']['cwv_pass'].' / '.$m['pagespeed']['measured'].' pass Core Web Vitals' }}</div>
            </div>

            {{-- GSC impressions --}}
            <div class="lp-metric">
                <div class="mv {{ $m['impressions']['empty'] ? 'muted' : '' }}">{{ $m['impressions']['empty'] ? '—' : number_format($m['impressions']['value']) }}</div>
                <div class="ml">Search impressions</div>
                <div class="ms">Last {{ $m['impressions']['days'] }} days</div>
            </div>

            {{-- GSC clicks --}}
            <div class="lp-metric">
                <div class="mv {{ $m['clicks']['empty'] ? 'muted' : '' }}">{{ $m['clicks']['empty'] ? '—' : number_format($m['clicks']['value']) }}</div>
                <div class="ml">Search clicks</div>
                <div class="ms">Last {{ $m['clicks']['days'] }} days</div>
            </div>

            {{-- Average position --}}
            <div class="lp-metric">
                <div class="mv {{ $m['avg_position']['empty'] ? 'muted' : '' }}">{{ $m['avg_position']['empty'] ? '—' : number_format($m['avg_position']['value'], 1) }}</div>
                <div class="ml">Average position</div>
                <div class="ms">Impression-weighted, last 28 days</div>
            </div>

            {{-- GA4 sessions --}}
            <div class="lp-metric">
                <div class="mv {{ $m['sessions']['empty'] ? 'muted' : '' }}">{{ $m['sessions']['empty'] ? '—' : number_format($m['sessions']['value']) }}</div>
                <div class="ml">Sessions</div>
                <div class="ms">Last {{ $m['sessions']['days'] }} days</div>
            </div>

            {{-- Indexed pages --}}
            <div class="lp-metric">
                <div class="mv {{ $m['indexed']['empty'] ? 'muted' : '' }}">{{ $m['indexed']['empty'] ? '—' : number_format($m['indexed']['value']) }}</div>
                <div class="ml">Indexed pages</div>
                <div class="ms">{{ $m['indexed']['empty'] ? 'Not measured yet' : 'of '.number_format($m['indexed']['known']).' known' }}</div>
            </div>

            {{-- Not indexed + reasons --}}
            <div class="lp-metric">
                <div class="mv {{ $m['not_indexed']['empty'] ? 'muted' : '' }}">{{ $m['not_indexed']['empty'] ? '—' : number_format($m['not_indexed']['value']) }}</div>
                <div class="ml">Not indexed</div>
                <div class="ms">{{ $m['not_indexed']['empty'] ? 'Nothing pending' : ($m['not_indexed']['reasons'][0]['label'] ?? '').' + '.max(0, count($m['not_indexed']['reasons']) - 1).' more reasons' }}</div>
            </div>

            {{-- Keywords --}}
            <div class="lp-metric">
                <div class="mv {{ $m['keywords']['empty'] ? 'muted' : '' }}">{{ $m['keywords']['empty'] ? '—' : number_format($m['keywords']['value']) }}</div>
                <div class="ml">Keywords</div>
                <div class="ms">Tracked targets</div>
            </div>

            {{-- Rankings --}}
            <div class="lp-metric">
                <div class="mv {{ $m['rankings']['empty'] ? 'muted' : '' }}">{{ $m['rankings']['empty'] ? '—' : number_format($m['rankings']['value']) }}</div>
                <div class="ml">Rankings</div>
                <div class="ms">{{ $m['rankings']['empty'] ? 'Not measured yet' : 'Top 3: '.$m['rankings']['top3'].' · Top 10: '.$m['rankings']['top10'] }}</div>
            </div>
        </div>

        {{-- Area cards — navigate to the tenant's working surfaces. Four targets (marked) are provisional
             pending the PR 5 nav IA. --}}
        <div class="lp-card">
            <h3>Areas</h3>
            <div class="lp-manage">
                @foreach ($this->areas as $area)
                    <a class="lp-manage-item" href="{{ $area['url'] }}" wire:navigate>
                        <span class="lp-manage-label">{{ $area['label'] }}</span>
                        <span class="lp-manage-desc">{{ $area['desc'] }}</span>
                        @if ($area['provisional'] ?? false)
                            <span class="prov">Target finalizes in nav cutover</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endunless
</x-lp.shell>
