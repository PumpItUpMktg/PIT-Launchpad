<x-filament-panels::page>
    @include('filament._lp-styles')
    <style>
        .lp-manage { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:10px; }
        .lp-manage-item { display:flex; flex-direction:column; gap:2px; padding:12px 14px; border:1px solid var(--line); border-radius:10px; text-decoration:none; background:#fff; transition:border-color .12s, box-shadow .12s; }
        .lp-manage-item:hover { border-color:#f59e0b; box-shadow:0 2px 8px rgba(15,23,42,.06); }
        .lp-manage-label { font-size:14px; font-weight:700; color:#0f172a; }
        .lp-manage-desc { font-size:12px; color:#64748b; }
        @media (prefers-color-scheme: dark) {
            .lp-manage-item { background:#151b24; }
            .lp-manage-label { color:#f1f5f9; }
        }
    </style>
    @php
        $site = $this->getSite();
        $stats = $this->stats;
        $funnel = $this->funnel;
        $reviewUrl = $this->reviewUrl();
        $funnelMax = max(1, ...array_values($funnel));
        $funnelLabels = ['candidate' => 'Candidates', 'scored' => 'Scored', 'drafted' => 'Drafted', 'in_review' => 'In review', 'approved' => 'Approved', 'published' => 'Published'];
    @endphp
    <div class="lpa">
        @unless ($site)
            <div class="lp-card">
                <x-lp.empty title="No sites yet" action="Add your first site" :href="\App\Filament\Resources\SiteResource::getUrl('create')">
                    Launchpad builds and feeds a WordPress site for each of your clients.
                </x-lp.empty>
            </div>
        @else
            <x-lp.page-header eyebrow="Per-site cockpit" :title="$site->brand_name" :scope="false">
                <x-slot:meta>
                    <x-lp.chip :for="$site->status" />
                </x-slot:meta>
                @if (count($this->siteOptions) > 1)
                    <x-slot:action>
                        <select wire:model.live="siteId" style="border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;background:#fff">
                            @foreach ($this->siteOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </x-slot:action>
                @endif
            </x-lp.page-header>

            {{-- Stat cards — counts link through to the actionable work items --}}
            <div class="lp-stats">
                <a class="lp-stat" href="{{ $reviewUrl }}"><div class="sn">{{ number_format($stats['needs_review']) }}</div><div class="sl">Needs review</div></a>
                <a class="lp-stat" href="{{ $reviewUrl }}"><div class="sn">{{ number_format($stats['approved_pending']) }}</div><div class="sl">Pages to publish</div></a>
                <div class="lp-stat"><div class="sn">{{ number_format($stats['published_this_week']) }}</div><div class="sl">Published this week</div></div>
                <a class="lp-stat" href="{{ $reviewUrl }}"><div class="sn {{ $stats['render_failed'] ? 'bad' : '' }}">{{ number_format($stats['render_failed']) }}</div><div class="sl">Render failed</div></a>
                <a class="lp-stat" href="{{ $reviewUrl }}"><div class="sn {{ $stats['publish_failed'] ? 'bad' : '' }}">{{ number_format($stats['publish_failed']) }}</div><div class="sl">Publish failed</div></a>
                <div class="lp-stat"><div class="sn">{{ number_format($stats['candidates']) }}</div><div class="sl">Candidates</div></div>
            </div>

            <div class="lp-card">
                <h3>Pipeline funnel</h3>
                <div class="lp-funnel">
                    @foreach ($funnelLabels as $key => $label)
                        <div class="fb">
                            <span class="fv">{{ $funnel[$key] ?? 0 }}</span>
                            <div class="bar" style="height:{{ (int) round((($funnel[$key] ?? 0) / $funnelMax) * 80) }}px"></div>
                            <span class="fl">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="lp-card">
                <h3>Content per silo</h3>
                @forelse ($this->perSilo as $silo)
                    <div class="lp-srow"><span>{{ $silo['silo_name'] }}</span><span class="lp-progtxt">{{ $silo['total'] }}</span></div>
                @empty
                    <div class="lp-empty" style="padding:16px">No content yet.</div>
                @endforelse
            </div>

            {{-- Manage — the single home for this tenant's config surfaces (they left the sidebar in
                 the nav-final pass; without this card each is a URL-only orphan). --}}
            <div class="lp-card">
                <h3>Manage {{ $site->brand_name }}</h3>
                <div class="lp-manage">
                    @foreach ($this->manageLinks as $link)
                        <a class="lp-manage-item" href="{{ $link['url'] }}" wire:navigate>
                            <span class="lp-manage-label">{{ $link['label'] }}</span>
                            <span class="lp-manage-desc">{{ $link['desc'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endunless
    </div>
</x-filament-panels::page>
