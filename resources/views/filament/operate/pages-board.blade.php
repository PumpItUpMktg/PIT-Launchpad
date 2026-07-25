<x-filament-panels::page>
    {{-- Shared by the three Operate pages boards (Core / Service / Location): the WORK lane on
         top (everything not yet published, morphing primary), the LIVE cards beneath. Reuses the
         Live boards' chrome + card partial wholesale — same wire method names by design. --}}
    <div class="lv-wrap">
        @include('filament.live.partials.shell-top', ['subtitle' => 'The whole '.strtolower(static::getNavigationLabel() ?? 'pages').' lifecycle on one board — work on top, live below. A page moves between the lanes by status alone.'])

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
                foreach ($named as $rows) {
                    $ordered = $rows->sortByDesc(fn ($r) => $r['is_brick_mortar'] ?? false)->values()->all();
                    $workGroups[] = ['label' => $rows->first()['brick_mortar'] ?? 'Location', 'rows' => $ordered];
                }
                if ($byLoc->has('')) {
                    $workGroups[] = ['label' => null, 'rows' => $byLoc->get('')->values()->all()];
                }
            }
        @endphp

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
        @if ($work === [])
            <div class="lv-empty">Nothing in progress — everything in this family is live (or not planned yet).</div>
        @elseif ($workGroups !== null)
            {{-- Location board: the in-progress pages grouped into a card per physical location (its
                 own landing page first, then its towns), with anything unassigned collected last. --}}
            @foreach ($workGroups as $g)
                <div class="pb-locgroup" wire:key="pbwg-{{ $loop->index }}">
                    <div class="pb-lochead">
                        @if ($g['label'] !== null)
                            <span class="pb-locpin">📍</span> {{ $g['label'] }}
                        @else
                            Unassigned
                        @endif
                        <span class="pb-loccount">· {{ count($g['rows']) }} page{{ count($g['rows']) === 1 ? '' : 's' }}</span>
                    </div>
                    <div class="pb-rows">
                        @foreach ($g['rows'] as $row)
                            @include('filament.operate.partials.pages-work-row', ['row' => $row])
                        @endforeach
                    </div>
                </div>
            @endforeach
        @else
            <div class="pb-rows">
                @foreach ($work as $row)
                    @include('filament.operate.partials.pages-work-row', ['row' => $row])
                @endforeach
            </div>
        @endif

        {{-- ─── Live lane ─── --}}
        @if ($isLocations)
            @foreach ($live['groups'] as $group)
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
            @endforeach
            @if ($live['orphans'] !== [])
                <div>
                    <div class="pb-band">Unassigned live town pages
                        <button type="button" class="lv-btn" style="margin-left:10px" wire:click="reassign">Re-run auto-assign</button>
                    </div>
                    <div class="lv-grid" style="margin-top:8px">
                        @foreach ($live['orphans'] as $card)
                            @include('filament.live.partials.card', ['card' => $card, 'locationOptions' => $live['location_options'], 'navControl' => true])
                        @endforeach
                    </div>
                </div>
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
