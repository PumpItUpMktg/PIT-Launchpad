<x-filament-panels::page>
    @include('filament._lp-styles')
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
            <div class="lp-card"><div class="lp-empty">No sites yet.</div></div>
        @else
            <div class="lp-row">
                <div>
                    <div class="lp-eyebrow">Per-site cockpit</div>
                    <div class="lp-h1">{{ $site->brand_name }}</div>
                    <span class="lp-status {{ $site->status->value === 'live' ? 'live' : 'onboarding' }}">{{ ucfirst($site->status->value) }}</span>
                </div>
                @if (count($this->siteOptions) > 1)
                    <select wire:model.live="siteId" style="border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;background:#fff">
                        @foreach ($this->siteOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

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
        @endunless
    </div>
</x-filament-panels::page>
