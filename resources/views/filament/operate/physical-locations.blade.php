<x-filament-panels::page>
    @include('filament.operate.partials.interactions')
    <style>
        .pl-wrap { display:flex; flex-direction:column; gap:14px; }
        .pl-head { display:flex; justify-content:space-between; align-items:flex-end; gap:14px; flex-wrap:wrap; }
        .pl-sub { color:#64748b; font-size:13px; max-width:66ch; margin:4px 0 0; }
        .pl-select { font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:4px 8px; background:transparent; }
        .pl-tiles { display:flex; gap:10px; flex-wrap:wrap; }
        .pl-tile { border:1px solid rgba(148,163,184,.35); border-radius:10px; padding:10px 16px; min-width:120px; }
        .pl-tile .n { font-size:21px; font-weight:700; font-variant-numeric:tabular-nums; }
        .pl-tile .l { font-size:11px; color:#94a3b8; }
        .pl-tile.bad { border-color:rgba(220,38,38,.5); } .pl-tile.bad .n { color:#dc2626; }
        .pl-tile.good { border-color:rgba(22,163,74,.5); } .pl-tile.good .n { color:#16a34a; }
        .pl-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:12px; }
        .pl-single { display:block; }
        .pl-loctabs { display:flex; gap:6px; flex-wrap:wrap; border-bottom:1px solid rgba(148,163,184,.25); padding-bottom:10px; }
        .pl-loctab { font-size:12.5px; font-weight:600; color:#475569; background:transparent; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:5px 11px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
        .pl-loctab:hover { border-color:rgba(100,116,139,.7); }
        .pl-loctab.on { background:#2563eb; border-color:#2563eb; color:#fff; }
        .pl-loctab.on .pl-locpin { color:#fff; }
        .pl-locpin { color:#2563eb; }
        .pl-loctab-n { font-variant-numeric:tabular-nums; font-size:10.5px; font-weight:700; background:rgba(148,163,184,.2); color:inherit; border-radius:99px; padding:0 6px; }
        .pl-loctab.on .pl-loctab-n { background:rgba(255,255,255,.25); }
        .pl-card { border:1px solid rgba(148,163,184,.35); border-radius:11px; display:flex; flex-direction:column; overflow:hidden; }
        .pl-top { display:flex; align-items:flex-start; gap:10px; padding:13px 15px; background:rgba(148,163,184,.07); border-bottom:1px solid rgba(148,163,184,.2); flex-wrap:wrap; }
        .pl-top h3 { margin:0; font-size:15.5px; }
        .pl-nap { font-size:12px; color:#94a3b8; margin-top:2px; }
        .pl-chips { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
        .pl-chip { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:2px 8px; border-radius:99px; background:rgba(148,163,184,.15); color:#64748b; white-space:nowrap; }
        .pl-chip.good { background:rgba(22,163,74,.12); color:#16a34a; }
        .pl-chip.warn { background:rgba(217,119,6,.13); color:#b45309; }
        .pl-chip.bad { background:rgba(220,38,38,.12); color:#dc2626; }
        .pl-chip a { color:inherit; text-decoration:none; }
        .pl-body { padding:12px 15px; display:flex; flex-direction:column; gap:10px; }
        .pl-stats { display:flex; gap:16px; flex-wrap:wrap; font-size:12.5px; color:#64748b; }
        .pl-stats b { font-variant-numeric:tabular-nums; }
        .pl-metrics { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; padding:11px 15px; border-top:1px solid rgba(148,163,184,.15); }
        .pl-m .k { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
        .pl-m .v { font-size:17px; font-weight:700; font-variant-numeric:tabular-nums; margin-top:1px; }
        .pl-m .v .up { color:#16a34a; } .pl-m .v .down { color:#dc2626; }
        .pl-m .sub { font-size:10.5px; color:#94a3b8; }
        .pl-m .pend { font-size:11.5px; color:#94a3b8; margin-top:3px; }
        .pl-towns { display:flex; gap:6px; flex-wrap:wrap; }
        .pl-town { font-size:11.5px; padding:2px 9px; border-radius:99px; border:1px solid rgba(148,163,184,.35); color:#64748b; }
        .pl-monitor { border:1px solid rgba(37,99,235,.3); background:rgba(37,99,235,.04); border-radius:11px; padding:12px 16px; display:flex; flex-direction:column; gap:8px; }
        .pl-mon-head { font-size:12.5px; font-weight:700; color:#334155; display:flex; align-items:center; gap:8px; }
        .pl-mon-sub { font-weight:600; font-size:11.5px; color:#64748b; }
        .pl-mon-bar { height:8px; border-radius:99px; background:rgba(148,163,184,.2); overflow:hidden; }
        .pl-mon-fill { height:100%; background:#16a34a; border-radius:99px; transition:width .4s; }
        .pl-mon-stats { display:flex; gap:14px; flex-wrap:wrap; font-size:11.5px; color:#64748b; }
        .pl-mon-stats .bad { color:#dc2626; font-weight:600; }
        .pl-townpanel { border-top:1px solid rgba(148,163,184,.15); padding-top:10px; display:flex; flex-direction:column; gap:9px; }
        .pl-band-hd { display:flex; align-items:center; gap:8px; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; margin-bottom:5px; }
        .pl-band-sel { font-weight:600; text-transform:none; letter-spacing:0; font-size:10.5px; color:#2563eb; cursor:pointer; background:none; border:0; padding:0; }
        .pl-townlist { display:flex; flex-wrap:wrap; gap:5px; }
        .pl-townrow { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; padding:2px 8px; border-radius:99px; border:1px solid rgba(148,163,184,.35); color:#475569; cursor:pointer; }
        .pl-townrow input { margin:0; cursor:pointer; }
        .pl-townrow.on { border-color:rgba(37,99,235,.5); background:rgba(37,99,235,.06); }
        .pl-townrow.pub { color:#16a34a; border-color:rgba(22,163,74,.4); }
        .pl-townrow.gen { color:#b45309; border-color:rgba(217,119,6,.4); }
        .pl-townrow.fail { color:#dc2626; border-color:rgba(220,38,38,.4); }
        .pl-townrow .st { font-size:9.5px; }
        .pl-genrow { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:2px; }
        .pl-review { border:1px solid rgba(217,119,6,.35); background:rgba(217,119,6,.05); border-radius:9px; padding:9px 12px; display:flex; flex-direction:column; gap:6px; }
        .pl-review-hd { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#b45309; }
        .pl-review-row { display:flex; align-items:center; gap:8px; font-size:12.5px; }
        .pl-review-name { margin-right:auto; color:#475569; }
        .pl-review .pl-btn { padding:3px 10px; font-size:11.5px; }
        .pl-band { border-radius:9px; padding:9px 12px; font-size:12.5px; line-height:1.5; }
        .pl-band.overlap { border:1px solid rgba(220,38,38,.4); color:#dc2626; }
        .pl-band.advisory { border:1px solid rgba(217,119,6,.4); color:#b45309; }
        .pl-band strong { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }
        .pl-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:15px; color:#94a3b8; font-size:13px; }
        .pl-link { font-size:12px; }
        .pl-foot { display:flex; align-items:center; gap:9px; flex-wrap:wrap; padding:10px 15px; border-top:1px solid rgba(148,163,184,.2); background:rgba(148,163,184,.04); }
        .pl-state { font-size:11.5px; color:#64748b; margin-right:auto; }
        .pl-state b { color:#334155; font-weight:600; }
        .pl-btn { font-size:12px; font-weight:600; padding:5px 12px; border-radius:7px; border:1px solid rgba(148,163,184,.5); background:transparent; color:#334155; cursor:pointer; }
        .pl-btn:hover { border-color:rgba(100,116,139,.8); }
        .pl-btn.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
        .pl-btn.primary:hover { background:#1d4ed8; }
        .pl-btn.danger { border-color:rgba(220,38,38,.5); color:#b91c1c; }
        .pl-btn.danger:hover { border-color:#dc2626; background:rgba(220,38,38,.06); }
        a.pl-btn { text-decoration:none; display:inline-flex; align-items:center; }
        .pl-btn[disabled] { opacity:.5; cursor:not-allowed; }
        .pl-queueinfo { border:1px solid rgba(37,99,235,.35); background:rgba(37,99,235,.06); color:#1e40af; border-radius:10px; padding:10px 15px; font-size:12.5px; line-height:1.5; }
        .pl-queuewarn { border:1px solid rgba(217,119,6,.5); background:rgba(217,119,6,.08); color:#b45309; border-radius:10px; padding:11px 15px; font-size:12.5px; line-height:1.55; }
        .pl-queuewarn code { background:rgba(217,119,6,.14); padding:1px 7px; border-radius:5px; font-size:11.5px; }
        .pl-qw-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .pl-qw-clear { flex-shrink:0; font-weight:700; font-size:11.5px; color:#fff; background:#d97706; border:1px solid #d97706; border-radius:7px; padding:4px 11px; cursor:pointer; white-space:nowrap; }
        .pl-qw-clear:hover { background:#b45309; border-color:#b45309; }
        .pl-qw-clear:disabled { opacity:.6; cursor:default; }
        .pl-qw-body { margin-top:4px; }
        .pl-qw-fails { margin-top:9px; border-top:1px solid rgba(217,119,6,.25); padding-top:8px; }
        .pl-qw-fails-h { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; font-weight:700; opacity:.8; margin-bottom:5px; }
        .pl-qw-fail { display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; padding:3px 0; font-size:12px; }
        .pl-qw-fail-job { font-weight:700; font-family:ui-monospace, monospace; }
        .pl-qw-fail-n { font-weight:700; font-size:10.5px; background:rgba(217,119,6,.18); border-radius:99px; padding:0 6px; }
        .pl-qw-fail-why { color:#92400e; flex:1; min-width:200px; }
        .pl-qw-fail-when { color:#a16207; font-size:11px; white-space:nowrap; }
        .pl-qw-fail-pages { flex-basis:100%; font-size:11.5px; color:#78350f; padding-left:2px; }
    </style>

    <div class="pl-wrap">
        @php $board = $this->board; $s = $board['summary']; @endphp

        <div class="pl-head">
            <div>
                <p class="pl-sub">Every location and the area it serves — the dispatch points the engine builds from. Overlap between locations is flagged per town (goal: zero). Soft rule, not a wall: a location should serve the county it sits in and that county's towns. <a href="{{ $this->serviceAreaUrl() }}" wire:navigate>Edit territory</a> · <a href="{{ $this->addLocationUrl() }}" wire:navigate>Add a location (GBP import)</a>.</p>
            </div>
            <select class="pl-select" wire:change="setSite($event.target.value)">
                @foreach ($this->siteOptions as $id => $label)
                    <option value="{{ $id }}" @selected($id === $this->siteId)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="pl-tiles">
            <div class="pl-tile"><div class="n">{{ $s['locations'] }}</div><div class="l">locations</div></div>
            <div class="pl-tile"><div class="n">{{ $s['towns_covered'] }}</div><div class="l">towns covered</div></div>
            <div class="pl-tile"><div class="n">{{ $s['towns_selected'] }}</div><div class="l">selected for pages</div></div>
            <div class="pl-tile {{ $s['overlaps'] > 0 ? 'bad' : 'good' }}"><div class="n">{{ $s['overlaps'] }}</div><div class="l">overlapping towns</div></div>
        </div>

        {{-- Stalled-worker banner: publishing is async (Repush queues a job the worker runs). If the
             worker is down, approved pages sit forever — so surface it here, with the drain escape hatch,
             instead of it looking like a broken button. --}}
        @php $q = $this->queueHealth; @endphp
        @if (! $q['stalled'] && $q['draining'])
            {{-- Healthy drain: a worker is publishing the backlog one page at a time. Informational, not an
                 alarm (a publish is render + WP push, so a queue of 9 legitimately takes minutes). Polls so
                 the count ticks down live. --}}
            <div class="pl-queueinfo" wire:poll.10s>
                ⏳ Publishing — <b>{{ $q['pending'] }}</b> page(s) queued, clearing one at a time. This is normal; each publish renders images then pushes to WordPress.
            </div>
        @endif
        @if ($q['stalled'])
            <div class="pl-queuewarn">
                <div class="pl-qw-head">
                    <span>@if ($q['worker_down'])⚠ The background worker looks down — <b>{{ $q['pending'] }}</b> job(s) queued{{ $q['oldest_minutes'] > 0 ? ' (oldest '.$q['oldest_minutes'].'m)' : '' }} and nothing is processing.@else⚠ <b>{{ $q['failed'] }}</b> job(s) failed{{ $q['pending'] > 0 ? ' — '.$q['pending'].' still queued' : '' }}.@endif</span>
                    @if ($q['failed'] > 0)
                        <button type="button" class="pl-qw-clear" wire:click="clearFailedJobs" wire:loading.attr="disabled" wire:target="clearFailedJobs"
                            wire:confirm="Clear {{ $q['failed'] }} failed job(s)? This removes the dead-job records (same as queue:flush) and clears this banner. Fix the cause first so they don't recur.">
                            <span wire:loading.remove wire:target="clearFailedJobs">Clear {{ $q['failed'] }} failed</span>
                            <span wire:loading wire:target="clearFailedJobs">Clearing…</span>
                        </button>
                    @endif
                </div>
                <div class="pl-qw-body">
                    @if ($q['worker_down'])
                        Approved pages won't publish until it drains. Fix the worker (Horizon / <code>queue:work</code>), or push this tenant's stuck pages now on the console:
                        <code>php artisan launchpad:drain-publish "{{ $q['brand'] }}"</code>
                    @else
                        The worker is running{{ $q['pending'] > 0 ? ' and draining the queue' : '' }} — these are past failures. Clear them below (fix the cause first so they don't recur){{ $q['pending'] > 0 ? ', or drain the rest inline: ' : '.' }}@if ($q['pending'] > 0)<code>php artisan launchpad:drain-publish "{{ $q['brand'] }}"</code>@endif
                    @endif
                </div>
                @if (($q['failures'] ?? []) !== [])
                    <div class="pl-qw-fails">
                        <div class="pl-qw-fails-h">What failed &amp; why</div>
                        @foreach ($q['failures'] as $f)
                            <div class="pl-qw-fail">
                                <span class="pl-qw-fail-job">{{ $f['job'] }}</span>
                                @if ($f['count'] > 1)<span class="pl-qw-fail-n">×{{ $f['count'] }}</span>@endif
                                <span class="pl-qw-fail-why">{{ $f['reason'] }}</span>
                                <span class="pl-qw-fail-when">last {{ $f['last'] }}</span>
                                @if (($f['pages'] ?? []) !== [])
                                    <span class="pl-qw-fail-pages">📄 {{ implode(', ', array_slice($f['pages'], 0, 8)) }}{{ count($f['pages']) > 8 ? ' +'.(count($f['pages']) - 8).' more' : '' }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Town-page pipeline monitor — live progress as the batches generate + publish (polls every
             15s). Publishing is async, so this is where the operator watches selected towns land. --}}
        @if ($board['pipeline']['selected'] > 0)
            @php $pl = $board['pipeline']; @endphp
            <div class="pl-monitor" wire:poll.15s>
                <div class="pl-mon-head">Town-page pipeline
                    <span class="pl-mon-sub">{{ $pl['published'] }} / {{ $pl['selected'] }} selected towns published</span>
                </div>
                <div class="pl-mon-bar"><div class="pl-mon-fill" style="width:{{ $pl['selected'] > 0 ? min(100, round($pl['published'] / $pl['selected'] * 100)) : 0 }}%"></div></div>
                <div class="pl-mon-stats">
                    <span>✅ {{ $pl['published'] }} published</span>
                    <span>⏳ {{ $pl['generating'] }} generating</span>
                    <span>📝 {{ $pl['drafted'] }} drafted</span>
                    <span>▫ {{ $pl['not_built'] }} to do</span>
                    @if ($pl['failed'] > 0)<span class="bad">✗ {{ $pl['failed'] }} failed</span>@endif
                    @if ($pl['queue_pending'] > 0)<span>· {{ $pl['queue_pending'] }} job(s) in queue</span>@endif
                </div>
            </div>
        @endif

        @if ($board['cards'] === [])
            <div class="pl-empty">No physical locations yet — import them on Setup → Business (bulk GBP) or add them in Settings → Locations.</div>
        @else
            @php
                // One TAB per location — the active one renders full-width (no card grid). Each tab shows a
                // published/selected badge so progress reads at a glance. Active tab from $this->locTab, else first.
                $locTabs = $board['cards'];
                $activeId = (is_string($this->locTab) && collect($locTabs)->contains('id', $this->locTab)) ? $this->locTab : ($locTabs[0]['id'] ?? null);
                $card = collect($locTabs)->firstWhere('id', $activeId) ?? $locTabs[0];
            @endphp
            <div class="pl-loctabs">
                @foreach ($locTabs as $t)
                    <button type="button" class="pl-loctab {{ $t['id'] === $card['id'] ? 'on' : '' }}" wire:click="setLocTab('{{ $t['id'] }}')" wire:key="lt-{{ $t['id'] }}">
                        <span class="pl-locpin">📍</span> {{ $t['name'] }}
                        <span class="pl-loctab-n">{{ $t['towns_selected'] }}</span>
                    </button>
                @endforeach
            </div>

            <div class="pl-single">
                    <div class="pl-card" wire:key="pl-{{ $card['id'] }}">
                        <div class="pl-top">
                            <div>
                                <h3>{{ $card['name'] }}</h3>
                                <div class="pl-nap">{{ $card['address'] ?: 'No address on file' }}{{ $card['phone'] ? ' · '.$card['phone'] : '' }}</div>
                            </div>
                            <div class="pl-chips">
                                <span class="pl-chip">{{ $card['storefront'] ? 'storefront' : 'service area' }}</span>
                                <span class="pl-chip {{ $card['located'] ? 'good' : 'warn' }}">{{ $card['located'] ? 'located' : 'not located' }}</span>
                                @if ($card['gbp_linked'])
                                    <span class="pl-chip good">@if ($card['gbp_url'])<a href="{{ $card['gbp_url'] }}" target="_blank" rel="noopener">GBP ↗</a>@else GBP linked @endif</span>
                                @else
                                    <span class="pl-chip warn">no GBP</span>
                                @endif
                                @if ($card['home_resolved'])
                                    <span class="pl-chip {{ $card['serves_home_county'] ? 'good' : 'warn' }}">{{ $card['serves_home_county'] ? 'serves home county' : 'home county unserved' }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="pl-body">
                            <div class="pl-stats">
                                <span><b>{{ $card['counties_served'] }}</b> {{ \Illuminate\Support\Str::plural('county', $card['counties_served']) }} served</span>
                                <span><b>{{ $card['towns_covered'] }}</b> towns covered</span>
                                <span><b>{{ $card['towns_selected'] }}</b> selected for pages</span>
                                @if ($card['home_county_towns'] !== null)
                                    <span><b>{{ $card['home_county_towns'] }}</b> in its home county</span>
                                @endif
                            </div>

                            {{-- Town pages: ordered by population into Larger / Mid-size / Smaller bands.
                                 Check which towns get pages (the territory choice). Draft them on Location
                                 pages; publish the drafted ones from here. --}}
                            @php $bandLabels = ['larger' => 'Larger cities', 'mid' => 'Mid-size towns', 'smaller' => 'Smaller communities']; @endphp
                            <div class="pl-townpanel">
                                @foreach ($bandLabels as $bk => $blabel)
                                    @if (($card['town_bands'][$bk] ?? []) !== [])
                                        <div>
                                            <div class="pl-band-hd">
                                                {{ $blabel }} · {{ count($card['town_bands'][$bk]) }}
                                                <button type="button" class="pl-band-sel" wire:click="selectBand('{{ $card['id'] }}', '{{ $bk }}', true)">select all</button>
                                                <button type="button" class="pl-band-sel" style="color:#94a3b8" wire:click="selectBand('{{ $card['id'] }}', '{{ $bk }}', false)">clear</button>
                                            </div>
                                            <div class="pl-townlist">
                                                @foreach ($card['town_bands'][$bk] as $town)
                                                    @php
                                                        $cls = match ($town['status']) { 'published' => 'pub', 'generating' => 'gen', 'failed' => 'fail', default => $town['page_selected'] ? 'on' : '' };
                                                        $icon = ['published' => '✅', 'generating' => '⏳', 'failed' => '✗', 'drafted' => '📝'][$town['status']] ?? '';
                                                    @endphp
                                                    <label class="pl-townrow {{ $cls }}" title="{{ ucfirst($town['status']) }}{{ $town['population'] ? ' · pop '.number_format($town['population']) : '' }}">
                                                        <input type="checkbox" @checked($town['page_selected']) wire:click="toggleTown('{{ $town['coverage_area_id'] }}')">
                                                        {{ $town['name'] }}@if ($icon)<span class="st">{{ $icon }}</span>@endif
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @endforeach

                                {{-- Publish flow: DRAFTING happens on Operate · Location pages. Here you select
                                     which towns get pages (above), push the drafted ones live, and drain a stuck
                                     queue. Generation (the Sonnet + fal call) is not on this surface. --}}
                                <div class="pl-genrow">
                                    <button type="button" class="pl-btn primary" wire:click="publishReviewedSelected('{{ $card['id'] }}')"
                                        @disabled($card['reviewable'] === 0)
                                        wire:confirm="Publish {{ $card['reviewable'] }} reviewed town page(s) live now? They push to WordPress synchronously."
                                        wire:loading.attr="disabled" wire:target="publishReviewedSelected('{{ $card['id'] }}')">
                                        🚀 Publish reviewed ({{ $card['reviewable'] }})
                                    </button>
                                    @if ($this->queueHealth['stalled'])
                                        <button type="button" class="pl-btn" wire:click="drainNow('{{ $card['id'] }}')"
                                            wire:confirm="Publish this location's stuck town pages right now, synchronously (no worker)?"
                                            wire:loading.attr="disabled" wire:target="drainNow('{{ $card['id'] }}')">Drain now</button>
                                    @endif
                                    <a class="pl-btn" href="{{ \App\Filament\Pages\Operate\OperateLocationPages::getUrl() }}" wire:navigate>✨ Generate on Location pages →</a>
                                </div>

                                {{-- Per-town review gate: each drafted, selected town — eyeball it in the
                                     proof editor, then publish just that one. --}}
                                @php
                                    $reviewTowns = collect(['larger', 'mid', 'smaller'])
                                        ->flatMap(fn ($b) => $card['town_bands'][$b] ?? [])
                                        ->filter(fn ($t) => $t['page_selected'] && $t['status'] === 'drafted' && $t['content_id'])
                                        ->values();
                                @endphp
                                @if ($reviewTowns->isNotEmpty())
                                    <div class="pl-review">
                                        <div class="pl-review-hd">📝 Drafted — review, then publish ({{ $reviewTowns->count() }})</div>
                                        @foreach ($reviewTowns as $t)
                                            <div class="pl-review-row" wire:key="rev-{{ $t['content_id'] }}">
                                                <span class="pl-review-name">{{ $t['name'] }}</span>
                                                <a class="pl-btn" href="{{ \App\Filament\Pages\ProofEditor::getUrl(['content' => $t['content_id']]) }}" wire:navigate>Review</a>
                                                <button type="button" class="pl-btn primary" wire:click="publishTown('{{ $t['content_id'] }}')"
                                                    wire:loading.attr="disabled" wire:target="publishTown('{{ $t['content_id'] }}')">Publish</button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if ($card['overlaps'] !== [])
                                <div class="pl-band overlap">
                                    <strong>Overlap — {{ count($card['overlaps']) }} town(s) also served by another location</strong>
                                    @foreach ($card['overlaps'] as $overlap)
                                        {{ $overlap['town'] }} (also {{ implode(', ', $overlap['with']) }})@if (! $loop->last) · @endif
                                    @endforeach
                                    <div style="margin-top:4px"><a class="pl-link" href="{{ $this->serviceAreaUrl() }}" wire:navigate>Resolve in the territory workspace →</a></div>
                                </div>
                            @endif

                            @foreach ($card['advisories'] as $advisory)
                                <div class="pl-band advisory">{{ $advisory }}</div>
                            @endforeach
                        </div>

                        @php $pg = $card['page']; @endphp

                        {{-- Tracking block — the SAME Position / GSC / GA4 metrics every page card shows,
                             with honest pending reasons ("No target keyword — brand page", "Connect …"). --}}
                        @if ($pg['metrics'])
                            @php $m = $pg['metrics']; @endphp
                            <div class="pl-metrics">
                                <div class="pl-m">
                                    <div class="k">Position</div>
                                    @if ($m['position']['rank'] !== null)
                                        <div class="v">#{{ $m['position']['rank'] }}
                                            @if (($m['position']['delta'] ?? null) > 0)<span class="up">▲{{ $m['position']['delta'] }}</span>
                                            @elseif (($m['position']['delta'] ?? null) < 0)<span class="down">▼{{ abs($m['position']['delta']) }}</span>@endif
                                        </div>
                                    @else
                                        <div class="pend">{{ $m['position']['pending'] ?? 'Pending' }}</div>
                                    @endif
                                </div>
                                <div class="pl-m">
                                    <div class="k">GSC · 28d</div>
                                    @if ($m['gsc']['impressions'] !== null)
                                        <div class="v">{{ number_format($m['gsc']['impressions']) }}</div>
                                        <div class="sub">impr · {{ number_format((int) $m['gsc']['clicks']) }} clicks</div>
                                    @else
                                        <div class="pend">{{ $m['gsc']['pending'] }}</div>
                                    @endif
                                </div>
                                <div class="pl-m">
                                    <div class="k">GA4 sessions</div>
                                    @if ($m['traffic']['sessions'] !== null)
                                        <div class="v">{{ number_format($m['traffic']['sessions']) }}</div>
                                        <div class="sub">28d</div>
                                    @else
                                        <div class="pend">{{ $m['traffic']['pending'] }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="pl-foot">
                            <span class="pl-state">Page: <b>{{ $pg['label'] }}</b></span>

                            @if ($pg['content_id'] ?? null)
                                {{-- Live-page management (this surface publishes + tracks; it doesn't draft).
                                     Plain single-label buttons (Livewire disables them mid-flight). --}}
                                <a class="pl-btn" href="{{ \App\Filament\Pages\ProofEditor::getUrl(['content' => $pg['content_id']]) }}" wire:navigate>Review</a>
                                <button class="pl-btn primary" wire:click="repush('{{ $pg['content_id'] }}')" wire:loading.attr="disabled" wire:target="repush('{{ $pg['content_id'] }}')">Repush</button>
                                <button class="pl-btn danger" wire:click="takeDown('{{ $pg['content_id'] }}')"
                                    wire:confirm="Remove this location page from WordPress? It stays in your plan and can be republished on the same URL."
                                    wire:loading.attr="disabled" wire:target="takeDown('{{ $pg['content_id'] }}')">Take down</button>
                            @else
                                {{-- No landing page yet — drafting lives on Location pages. --}}
                                <a class="pl-btn primary" href="{{ \App\Filament\Pages\Operate\OperateLocationPages::getUrl() }}" wire:navigate>Generate on Location pages →</a>
                            @endif
                        </div>
                    </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
