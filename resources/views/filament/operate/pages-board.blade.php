<x-filament-panels::page>
    {{-- Shared by the three Operate pages boards (Core / Service / Location): the WORK lane on
         top (everything not yet published, morphing primary), the LIVE cards beneath. Reuses the
         Live boards' chrome + card partial wholesale — same wire method names by design. --}}
    <div class="lv-wrap">
        @include('filament.live.partials.shell-top', ['subtitle' => 'The whole '.strtolower(static::getNavigationLabel() ?? 'pages').' lifecycle on one board — work on top, live below. A page moves between the lanes by status alone.'])

        {{-- Stalled-worker banner: publishing is async (Publish/Repush queue a job the worker runs). If the
             worker is down, approved pages sit at "publishing" forever — surface it here with the inline
             "Publish stuck pages now" drain, instead of it looking like a broken button. --}}
        @php $q = $this->queueHealth; @endphp
        @if (! $q['stalled'] && $q['draining'])
            <div wire:poll.10s style="display:flex; align-items:center; gap:8px; font-size:12.5px; color:#1d4ed8; border:1px solid rgba(37,99,235,.3); background:rgba(37,99,235,.06); border-radius:11px; padding:10px 14px; margin-bottom:14px;">
                ⏳ Publishing — <b>{{ $q['pending'] }}</b> job(s) queued, clearing one at a time. This is normal; each publish renders images then pushes to WordPress.
            </div>
        @endif
        @if ($q['stalled'])
            <div style="border:1px solid rgba(220,38,38,.4); background:rgba(220,38,38,.05); border-radius:12px; padding:14px 16px; margin-bottom:14px;">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-weight:700; font-size:13.5px; color:#b91c1c;">
                    <span>@if ($q['worker_down'])⚠ The background worker looks down — <b>{{ $q['pending'] }}</b> job(s) queued{{ $q['oldest_minutes'] > 0 ? ' (oldest '.$q['oldest_minutes'].'m)' : '' }} and nothing is processing.@else⚠ <b>{{ $q['failed'] }}</b> job(s) failed{{ $q['pending'] > 0 ? ' — '.$q['pending'].' still queued' : '' }}.@endif</span>
                </div>
                <div style="font-size:12.5px; color:#64748b; margin:6px 0 10px;">
                    @if ($q['worker_down'])
                        Approved pages won’t publish until it drains. Publish this tenant’s stuck pages now with the button below, or fix the worker (Horizon / <code>queue:work</code>). On the console: <code>php artisan launchpad:drain-publish "{{ $q['brand'] }}"</code>
                    @else
                        The worker is running{{ $q['pending'] > 0 ? ' and draining the queue' : '' }} — these are past failures. Clear them below (fix the cause first so they don’t recur){{ $q['pending'] > 0 ? ', or drain the rest now.' : '.' }}
                    @endif
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="lv-btn primary" wire:click="drainStuckPages" wire:loading.attr="disabled" wire:target="drainStuckPages">
                        <span wire:loading.remove wire:target="drainStuckPages">Publish stuck pages now</span>
                        <span wire:loading wire:target="drainStuckPages">Publishing…</span>
                    </button>
                    @if ($q['failed'] > 0)
                        <button type="button" class="lv-btn" wire:click="clearFailedJobs" wire:loading.attr="disabled" wire:target="clearFailedJobs"
                            wire:confirm="Clear {{ $q['failed'] }} failed job(s)? This removes the dead-job records (same as queue:flush). Fix the cause first so they don’t recur.">
                            Clear {{ $q['failed'] }} failed
                        </button>
                    @endif
                </div>
                @if (($q['failures'] ?? []) !== [])
                    <div style="margin-top:10px; font-size:12px;">
                        <div style="font-weight:600; color:#475569; margin-bottom:4px;">What failed &amp; why</div>
                        @foreach ($q['failures'] as $f)
                            <div style="color:#64748b; padding:2px 0;">
                                <span style="font-family:ui-monospace,monospace; color:#334155;">{{ $f['job'] }}</span>@if ($f['count'] > 1) <span style="color:#94a3b8;">×{{ $f['count'] }}</span>@endif
                                — {{ $f['reason'] }} <span style="color:#94a3b8;">(last {{ $f['last'] }})</span>
                                @if (($f['pages'] ?? []) !== [])<div style="color:#94a3b8;">📄 {{ implode(', ', array_slice($f['pages'], 0, 8)) }}{{ count($f['pages']) > 8 ? ' +'.(count($f['pages']) - 8).' more' : '' }}</div>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Ordering-guard interstitial (report fix 2): this page links into pages that aren't live yet.
             Push them first (so the links resolve), or publish anyway (recorded in the audit log). --}}
        @if ($confirmingPublish !== null)
            <div style="border:1px solid rgba(220,38,38,.4); background:rgba(220,38,38,.05); border-radius:12px; padding:14px 16px; margin-bottom:14px;">
                <div style="font-weight:700; font-size:14px; color:#b91c1c;">Publishing this page would create dead links</div>
                <div style="font-size:12.5px; color:#64748b; margin:5px 0 8px;">It links to {{ count($confirmBlockers) }} page(s) that aren’t published yet:</div>
                <ul style="margin:0 0 10px 18px; font-size:12.5px; color:#475569;">
                    @foreach ($confirmBlockers as $b)
                        <li>{{ $b['title'] }} <span style="color:#94a3b8;">({{ $b['kind'] }})</span></li>
                    @endforeach
                </ul>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button class="lv-btn primary" wire:click="pushSpokesFirst" wire:loading.attr="disabled">Push those first, then this</button>
                    <button class="lv-btn" wire:click="publishAnyway" wire:loading.attr="disabled"
                            wire:confirm="Publish into dead links anyway? This is recorded in the audit log.">Publish anyway</button>
                    <button class="lv-btn" wire:click="cancelPublish">Cancel</button>
                </div>
            </div>
        @endif

        <style>
            .pb-band { font-size:10px; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; }
            .pb-rows { border:1px solid rgba(148,163,184,.35); border-radius:11px; }
            .pb-row { display:flex; align-items:center; gap:10px; padding:10px 14px; border-bottom:1px solid rgba(148,163,184,.15); flex-wrap:wrap; }
            .pb-row:last-child { border-bottom:0; }
            .pb-title { font-weight:600; font-size:13.5px; }
            .pb-bm { font-weight:600; font-size:10.5px; color:#2563eb; background:rgba(37,99,235,.1); border:1px solid rgba(37,99,235,.22); padding:1px 7px; border-radius:99px; margin-left:6px; white-space:nowrap; vertical-align:middle; }
            a.pb-enrich { font-weight:600; font-size:10.5px; color:#b45309; background:rgba(217,119,6,.12); border:1px solid rgba(217,119,6,.3); padding:1px 7px; border-radius:99px; margin-left:6px; white-space:nowrap; vertical-align:middle; text-decoration:none; }
            a.pb-enrich:hover { background:rgba(217,119,6,.2); }
            .pb-generate { font-weight:600; font-size:10.5px; color:#b45309; background:rgba(217,119,6,.12); border:1px solid rgba(217,119,6,.3); padding:1px 7px; border-radius:99px; margin-left:6px; white-space:nowrap; vertical-align:middle; cursor:help; }
            .pb-perma { font-size:11.5px; color:#94a3b8; font-family:ui-monospace, monospace; }
            .pb-move { font-size:12px; color:#64748b; }
            .pb-tail { font-size:11.5px; color:#64748b; margin-top:3px; line-height:1.4; }
            .pb-tail.err { color:#dc2626; }
            .pb-tone { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:3px 9px; border-radius:6px; white-space:nowrap; }
            .pb-tone.ok { color:#16a34a; background:rgba(22,163,74,.12); }
            .pb-tone.warn { color:#b45309; background:rgba(217,119,6,.13); }
            .pb-tone.danger { color:#dc2626; background:rgba(220,38,38,.12); }
            .pb-tone.info { color:#6366f1; background:rgba(79,70,229,.12); }
            .pb-tone.idle { color:#64748b; background:rgba(148,163,184,.15); }
            .pb-right { margin-left:auto; display:flex; gap:7px; align-items:center; flex-wrap:wrap; }
            .pb-reject { flex-basis:100%; display:flex; gap:8px; align-items:center; padding-top:6px; }
            .pb-reject input { flex:1; font-size:12.5px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:5px 9px; background:transparent; }
            .pb-locgroup { margin-bottom:14px; }
            .pb-lochead { font-size:12px; font-weight:700; color:#334155; padding:2px 2px 6px; display:flex; align-items:center; gap:7px; }
            .pb-locpin { color:#2563eb; }
            .pb-loccount { font-weight:600; font-size:10.5px; color:#64748b; }
            .pb-loctabs { display:flex; gap:6px; flex-wrap:wrap; margin:10px 0 14px; border-bottom:1px solid rgba(148,163,184,.25); padding-bottom:10px; }
            .pb-loctab { font-size:12.5px; font-weight:600; color:#475569; background:transparent; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:5px 11px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
            .pb-loctab:hover { border-color:rgba(100,116,139,.7); }
            .pb-loctab.on { background:#2563eb; border-color:#2563eb; color:#fff; }
            .pb-loctab.on .pb-locpin { color:#fff; }
            .pb-loctab-n { font-variant-numeric:tabular-nums; font-size:10.5px; font-weight:700; background:rgba(148,163,184,.2); color:inherit; border-radius:99px; padding:0 6px; }
            .pb-loctab.on .pb-loctab-n { background:rgba(255,255,255,.25); }
            .pb-gbp { border:1px solid rgba(37,99,235,.25); background:rgba(37,99,235,.04); border-radius:11px; padding:12px 15px; margin:0 0 14px; }
            .pb-gbp-name { font-weight:700; font-size:15px; color:#1e293b; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
            .pb-gbp-cat { font-weight:600; font-size:10.5px; color:#2563eb; background:rgba(37,99,235,.1); border:1px solid rgba(37,99,235,.22); padding:1px 8px; border-radius:99px; }
            .pb-gbp-badge { font-weight:600; font-size:10.5px; color:#64748b; background:rgba(148,163,184,.18); padding:1px 8px; border-radius:99px; }
            .pb-gbp-lines { display:flex; gap:7px 20px; flex-wrap:wrap; margin-top:6px; font-size:12.5px; color:#475569; }
            .pb-gbp-line { display:inline-flex; align-items:center; gap:6px; }
            .pb-gbp-line .k { color:#94a3b8; }
            .pb-gbp-line a { color:#2563eb; text-decoration:none; }
            .pb-gbp-line a:hover { text-decoration:underline; }
            .pb-gbp-missing { color:#b45309; font-style:italic; }
        </style>

        @php
            $board = $this->board;
            $work = $board['work'];
            $live = $board['live'];
            $isLocations = array_key_exists('groups', is_array($live) ? $live : []);
            $readyCount = collect($work)->filter(fn ($r) => in_array('generate', $r['actions'] ?? [], true))->count();

            // For the Location board, group the work lane into a card per physical location — the
            // location's own landing page first, then its town / city-service pages — with anything
            // unassigned collected last. Ordered by location label; mirrors the live side's grouping.
            $workGroups = null;
            if ($isLocations && $work !== []) {
                $byLoc = collect($work)->groupBy(fn ($r) => $r['brick_mortar_id'] ?? '');
                $named = $byLoc->except('')->sortBy(fn ($rows) => $rows->first()['brick_mortar'] ?? '~');
                $workGroups = [];
                foreach ($named as $locId => $rows) {
                    $ordered = $rows->sortByDesc(fn ($r) => $r['is_brick_mortar'] ?? false)->values()->all();
                    $workGroups[] = ['id' => (string) $locId, 'label' => $rows->first()['brick_mortar'] ?? 'Location', 'rows' => $ordered];
                }
                if ($byLoc->has('')) {
                    $workGroups[] = ['id' => 'unassigned', 'label' => null, 'rows' => $byLoc->get('')->values()->all()];
                }
            }

            // Location board only: fold the per-location work + live groups into TABS so the operator
            // works one location at a time instead of scrolling one long list.
            $locTabs = null; $activeTab = null;
            if ($isLocations) {
                $tabs = [];
                foreach ($workGroups ?? [] as $g) {
                    $id = $g['id'];
                    $tabs[$id] ??= ['id' => $id, 'label' => $g['label'] ?? 'Unassigned', 'work' => [], 'live' => null, 'orphans' => []];
                    $tabs[$id]['work'] = $g['rows'];
                }
                foreach (($live['groups'] ?? []) as $group) {
                    $id = (string) $group['location']['id'];
                    $label = $group['location']['name'] !== '' ? $group['location']['name'] : $group['location']['city'];
                    $tabs[$id] ??= ['id' => $id, 'label' => $label, 'work' => [], 'live' => null, 'orphans' => []];
                    $tabs[$id]['label'] = $label;
                    $tabs[$id]['live'] = $group;
                }
                if (($live['orphans'] ?? []) !== []) {
                    $tabs['unassigned'] ??= ['id' => 'unassigned', 'label' => 'Unassigned', 'work' => [], 'live' => null, 'orphans' => []];
                    $tabs['unassigned']['orphans'] = $live['orphans'];
                }
                // Named locations A→Z, "Unassigned" always last.
                $locTabs = collect($tabs)->sortBy(fn ($t) => $t['id'] === 'unassigned' ? '~~~~' : mb_strtolower((string) $t['label']))->values()->all();
                $ids = array_column($locTabs, 'id');
                $activeTab = (is_string($this->locTab) && in_array($this->locTab, $ids, true))
                    ? collect($locTabs)->firstWhere('id', $this->locTab)
                    : ($locTabs[0] ?? null);
            }
        @endphp

        {{-- GBP identity header for the active location — Name / Address / Phone up top, so the operator
             knows exactly which business they're working on (some GBP titles omit the city). --}}
        @if ($isLocations && $activeTab !== null && ($activeTab['live']['location'] ?? null) !== null)
            @php $loc = $activeTab['live']['location']; @endphp
            <div class="pb-gbp">
                <div class="pb-gbp-name">
                    {{ $loc['name'] !== '' ? $loc['name'] : ($loc['city'] !== '' ? $loc['city'] : 'Location') }}
                    @if (($loc['category'] ?? '') !== '')<span class="pb-gbp-cat">{{ $loc['category'] }}</span>@endif
                    <span class="pb-gbp-badge">{{ $loc['storefront'] ? 'storefront' : 'service area' }}</span>
                </div>
                <div class="pb-gbp-lines">
                    <span class="pb-gbp-line"><span class="k">📍</span>
                        @php $addr = trim($loc['address'] ?? ''); @endphp
                        @if ($addr === '' && (($loc['city'] ?? '') !== '' || ($loc['state'] ?? '') !== ''))
                            @php $addr = trim(($loc['city'] ?? '').(($loc['city'] ?? '') !== '' && ($loc['state'] ?? '') !== '' ? ', ' : '').($loc['state'] ?? '')); @endphp
                        @endif
                        @if ($addr !== ''){{ $addr }}@else<span class="pb-gbp-missing">No address on file</span>@endif
                    </span>
                    <span class="pb-gbp-line"><span class="k">📞</span>
                        @if (($loc['phone'] ?? '') !== ''){{ $loc['phone'] }}@else<span class="pb-gbp-missing">No phone on file</span>@endif
                    </span>
                    @if (($loc['served'] ?? []) !== [])
                        <span class="pb-gbp-line"><span class="k">Serves</span>{{ implode(', ', array_slice($loc['served'], 0, 6)) }}{{ count($loc['served']) > 6 ? ' +'.(count($loc['served']) - 6).' more' : '' }}</span>
                    @endif
                    @if (($loc['gbp_url'] ?? '') !== '')
                        <span class="pb-gbp-line"><a href="{{ $loc['gbp_url'] }}" target="_blank" rel="noopener">View GBP ↗</a></span>
                    @endif
                </div>
            </div>
        @endif

        {{-- ─── Work lane ─── --}}
        <div class="pb-band">In progress · {{ count($work) }}
            <button type="button" class="lv-btn" style="margin-left:10px" wire:click="syncPlan" wire:loading.attr="disabled" wire:target="syncPlan"
                title="Added a service or location since launch? Sync picks it up as a new planned page.">
                <span wire:loading.remove wire:target="syncPlan">↻ Sync plan</span>
                <span wire:loading wire:target="syncPlan">Syncing…</span>
            </button>
            @if ($readyCount > 0)
                <button type="button" class="lv-btn primary" style="margin-left:6px" wire:click="generateAllReady" wire:loading.attr="disabled" wire:target="generateAllReady"
                    wire:confirm="Generate all {{ $readyCount }} ready page(s)? Each drafts on the worker (AI copy + images). Pages that aren't ready yet are skipped."
                    title="Draft every page that's ready to write — the one-click fill after Sync plan.">
                    <span wire:loading.remove wire:target="generateAllReady">✨ Generate all ready ({{ $readyCount }})</span>
                    <span wire:loading wire:target="generateAllReady">Queuing…</span>
                </button>
            @endif
            @if ($isLocations)
                <button type="button" class="lv-btn" style="margin-left:6px" wire:click="reassign" wire:loading.attr="disabled" wire:target="reassign"
                    title="Tag each town page with the GBP location that serves it (from the intake coverage areas).">
                    <span wire:loading.remove wire:target="reassign">📍 Assign GBP</span>
                    <span wire:loading wire:target="reassign">Assigning…</span>
                </button>
            @endif
        </div>

        {{-- Location board: one TAB per physical location — work one location at a time. --}}
        @if ($isLocations && $locTabs && count($locTabs) > 0)
            <div class="pb-loctabs">
                @foreach ($locTabs as $t)
                    @php $tActive = $activeTab !== null && $t['id'] === $activeTab['id']; @endphp
                    <button type="button" class="pb-loctab {{ $tActive ? 'on' : '' }}" wire:click="setLocTab('{{ $t['id'] }}')" wire:key="lt-{{ $t['id'] }}">
                        @if ($t['id'] !== 'unassigned')<span class="pb-locpin">📍</span>@endif {{ $t['label'] }}
                        <span class="pb-loctab-n">{{ count($t['work']) + ($t['live'] !== null ? ($t['live']['rollup']['towns_live'] ?? 0) : 0) + count($t['orphans']) }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        @php $tabWork = $isLocations ? ($activeTab['work'] ?? []) : $work; @endphp
        @if ($isLocations)
            {{-- Active location's in-progress pages. --}}
            @if ($tabWork === [])
                <div class="lv-empty">No in-progress pages for this location — everything here is live (or not planned yet).</div>
            @else
                <div class="pb-rows">
                    @foreach ($tabWork as $row)
                        @include('filament.operate.partials.pages-work-row', ['row' => $row])
                    @endforeach
                </div>
            @endif
        @elseif ($work === [])
            <div class="lv-empty">Nothing in progress — everything in this family is live (or not planned yet).</div>
        @else
            <div class="pb-rows">
                @foreach ($work as $row)
                    @include('filament.operate.partials.pages-work-row', ['row' => $row])
                @endforeach
            </div>
        @endif

        {{-- ─── Live lane (active location tab only) ─── --}}
        @if ($isLocations)
            @php $group = $activeTab['live'] ?? null; @endphp
            @if ($group !== null)
                <div class="lv-locgroup" wire:key="pbg-{{ $group['location']['id'] }}">
                    <div class="lv-loccard">
                        <div class="id">
                            <h2>{{ $group['location']['name'] !== '' ? $group['location']['name'] : $group['location']['city'] }}
                                <span class="badge">{{ $group['location']['storefront'] ? 'storefront' : 'service area' }}</span>
                            </h2>
                            @if ($group['location']['served'] !== [])
                                <div class="serves">Serves {{ implode(', ', $group['location']['served']) }}</div>
                            @endif
                        </div>
                        <div class="lv-locstats">
                            <div class="lv-locstat"><div class="n">{{ $group['rollup']['towns_live'] }}</div><div class="l">town pages live</div></div>
                            <div class="lv-locstat"><div class="n">{{ $group['rollup']['avg_rank'] !== null ? '#'.$group['rollup']['avg_rank'] : '—' }}</div><div class="l">avg position</div></div>
                            <div class="lv-locstat"><div class="n">{{ $group['rollup']['impressions'] !== null ? number_format($group['rollup']['impressions']) : '—' }}</div><div class="l">impressions · 28d</div></div>
                        </div>
                    </div>
                    @if ($group['location_card'] !== null)
                        <div class="lv-band">Location page</div>
                        <div class="lv-towns">@include('filament.live.partials.card', ['card' => $group['location_card'], 'navControl' => true])</div>
                    @endif
                    @if ($group['towns'] !== [])
                        <div class="lv-band">Town pages</div>
                        <div class="lv-towns">
                            @foreach ($group['towns'] as $card)
                                @include('filament.live.partials.card', ['card' => $card, 'navControl' => true])
                            @endforeach
                        </div>
                    @endif
                    @if ($group['city_services'] !== [])
                        <div class="lv-band">City-service pages</div>
                        <div class="lv-towns">
                            @foreach ($group['city_services'] as $card)
                                @include('filament.live.partials.card', ['card' => $card, 'navControl' => true])
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
            @if (($activeTab['orphans'] ?? []) !== [])
                <div>
                    <div class="pb-band">Unassigned live town pages
                        <button type="button" class="lv-btn" style="margin-left:10px" wire:click="reassign">Re-run auto-assign</button>
                    </div>
                    <div class="lv-grid" style="margin-top:8px">
                        @foreach ($activeTab['orphans'] as $card)
                            @include('filament.live.partials.card', ['card' => $card, 'locationOptions' => $live['location_options'], 'navControl' => true])
                        @endforeach
                    </div>
                </div>
            @elseif ($group === null)
                <div class="lv-empty">Nothing published for this location yet.</div>
            @endif
        @else
            <div class="pb-band">Live · {{ count($live) }}</div>
            @if ($live === [])
                <div class="lv-empty">Nothing published in this family yet.</div>
            @else
                <div class="lv-grid">
                    @foreach ($live as $card)
                        @include('filament.live.partials.card', ['card' => $card, 'navControl' => true])
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
