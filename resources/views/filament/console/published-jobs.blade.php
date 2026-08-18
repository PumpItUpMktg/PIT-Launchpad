<x-filament-panels::page>
    @include('filament.console.partials.site-switcher')

    @php $published = $this->publishedJobs; $pipeline = $this->pipelineJobs; @endphp

    <style>
        .pj-wrap { display:flex; flex-direction:column; gap:22px; }
        .pj-sec h3 { margin:0 0 4px; font-size:15px; }
        .pj-sub { font-size:12.5px; color:#94a3b8; margin:0 0 12px; }
        .pj-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px,1fr)); gap:14px; }
        .pj-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; }
        .pj-card.warn { border-color:rgba(220,38,38,.5); }
        .pj-photo { width:100%; height:150px; background:rgba(148,163,184,.12); }
        .pj-photo img { width:100%; height:100%; object-fit:cover; display:block; }
        .pj-body { padding:12px 14px; display:flex; flex-direction:column; gap:8px; }
        .pj-title { margin:0; font-size:14px; font-weight:700; }
        .pj-meta { font-size:12px; color:#64748b; }
        .pj-chips { display:flex; gap:6px; flex-wrap:wrap; }
        .pj-chip { font-size:10.5px; font-weight:600; padding:2px 8px; border-radius:99px; background:rgba(56,189,248,.14); color:#0284c7; }
        .pj-chip.store { background:rgba(79,70,229,.14); color:#4f46e5; }
        .pj-actions { display:flex; gap:6px; flex-wrap:wrap; margin-top:2px; }
        .pj-btn.danger { border-color:rgba(220,38,38,.45); color:#dc2626; }
        .pj-badge { font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:99px; align-self:flex-start; }
        .pj-badge.live { background:rgba(22,163,74,.15); color:#15803d; }
        .pj-badge.wait { background:rgba(202,138,4,.16); color:#a16207; }
        .pj-badge.fail { background:rgba(220,38,38,.15); color:#dc2626; }
        .pj-pills { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .pj-bing { font-size:10px; font-weight:700; padding:2px 8px; border-radius:99px; background:rgba(37,99,235,.12); color:#2563eb; }
        .pj-err { font-size:11.5px; color:#dc2626; word-break:break-word; }
        .pj-foot { font-size:11.5px; color:#94a3b8; display:flex; justify-content:space-between; align-items:center; gap:8px; }
        .pj-btn { font-size:12px; font-weight:600; padding:6px 12px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.45); background:transparent; color:inherit; }
        .pj-empty { color:#94a3b8; font-size:13px; }
    </style>

    @php
        $badge = fn (string $s) => $s === 'published' ? 'live' : ($s === 'publish_failed' ? 'fail' : 'wait');
    @endphp

    <div class="pj-wrap">
        @if (count($pipeline) > 0)
            <div class="pj-sec">
                <h3>In the publish pipeline</h3>
                <p class="pj-sub">Approved but not live on WordPress yet. If one is stuck (or the queue worker wasn’t running when it was approved), re-publish it here.</p>
                <div class="pj-grid">
                    @foreach ($pipeline as $j)
                        <div class="pj-card {{ $j['status'] === 'publish_failed' ? 'warn' : '' }}" wire:key="pipe-{{ $j['id'] }}">
                            @if ($j['photo'])<div class="pj-photo"><img src="{{ $j['photo'] }}" alt=""></div>@endif
                            <div class="pj-body">
                                <span class="pj-badge {{ $badge($j['status']) }}">{{ $j['status_label'] }}</span>
                                <p class="pj-title">{{ $j['title'] }}</p>
                                <div class="pj-meta">{{ collect([$j['city'], $j['county']])->filter()->implode(', ') ?: 'Location pending' }}</div>
                                @if (count($j['job_types']) > 0 || $j['storefront'])
                                    <div class="pj-chips">
                                        @foreach ($j['job_types'] as $t)<span class="pj-chip">{{ $t }}</span>@endforeach
                                        @if ($j['storefront'])<span class="pj-chip store">{{ $j['storefront'] }}</span>@endif
                                    </div>
                                @endif
                                @if ($j['error'])<div class="pj-err">{{ $j['error'] }}</div>@endif
                                <div class="pj-actions">
                                    <button class="pj-btn" wire:click="retryPublish('{{ $j['id'] }}')">Publish now</button>
                                    <button class="pj-btn" wire:click="editInReview('{{ $j['id'] }}')">Edit</button>
                                </div>
                                <div class="pj-foot"><span>{{ $j['when'] ? 'updated '.$j['when'] : '' }}</span></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="pj-sec">
            <h3>Published ({{ count($published) }})</h3>
            <p class="pj-sub">Jobs live on the client’s WordPress site.</p>
            @if (count($published) === 0)
                <div class="pj-empty">No published jobs on this site yet. Approved jobs land here once they reach WordPress.</div>
            @else
                <div class="pj-grid">
                    @foreach ($published as $j)
                        <div class="pj-card" wire:key="pub-{{ $j['id'] }}">
                            @if ($j['photo'])<div class="pj-photo"><img src="{{ $j['photo'] }}" alt=""></div>@endif
                            <div class="pj-body">
                                <div class="pj-pills">
                                    <span class="pj-badge live">Published</span>
                                    @if ($j['indexnow_at'])<span class="pj-bing" title="Submitted to IndexNow on {{ $j['indexnow_at'] }}">↗ Submitted to Bing</span>@endif
                                </div>
                                <p class="pj-title">{{ $j['title'] }}</p>
                                <div class="pj-meta">{{ collect([$j['city'], $j['county']])->filter()->implode(', ') ?: '—' }}</div>
                                @if (count($j['job_types']) > 0 || $j['storefront'])
                                    <div class="pj-chips">
                                        @foreach ($j['job_types'] as $t)<span class="pj-chip">{{ $t }}</span>@endforeach
                                        @if ($j['storefront'])<span class="pj-chip store">{{ $j['storefront'] }}</span>@endif
                                    </div>
                                @endif
                                <div class="pj-actions">
                                    <button class="pj-btn" wire:click="editInReview('{{ $j['id'] }}')">Edit</button>
                                    <button class="pj-btn" wire:click="retryPublish('{{ $j['id'] }}')">Repush</button>
                                    <button class="pj-btn danger" wire:click="takeDown('{{ $j['id'] }}')" wire:confirm="Take '{{ $j['title'] }}' down from WordPress? It stays here as approved and can be re-pushed.">Take down</button>
                                </div>
                                <div class="pj-foot">
                                    <span>{{ $j['when'] ? 'published '.$j['when'] : '' }}</span>
                                    <span>{{ $j['wp_post_id'] ? 'WP #'.$j['wp_post_id'] : '' }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
