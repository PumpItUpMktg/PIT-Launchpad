{{-- The Indexing board's watchlist block. Expects $this->watchlist, $watchSort, $watchDir. --}}
{{-- The watchlist: every published page not yet indexed, oldest first, then the ones that landed in
     the last few days. Plain = published, not yet inspected; amber = inspected, not indexed (with
     Google's reason); green = indexed, shown for `watch_days` days then gone. --}}
@php($watch = $this->watchlist)
@php($stuckDays = $watch['metrics']['stuck_days'] ?? 10)
@php($stuckReport = $whyId !== null ? $this->stuck : null)
@php($stuck = $stuckReport['rows'] ?? [])
@php($stuckPending = $whyId !== null && $stuckReport === null && $this->stuckPending)
<style>
    .ix-watch .ix-why { display:inline-block; font-size:11px; font-weight:700; border:1px solid var(--line); background:#fff; border-radius:7px; padding:3px 8px; cursor:pointer; color:var(--ink); margin-left:6px; white-space:nowrap; text-decoration:none; }
    .ix-watch a.ix-sort { text-decoration:none; display:inline-block; }
    .ix-watch .ix-why.on { border-color:var(--teal); color:var(--teal-deep); }
    .ix-watch tr.ix-panel td { background:var(--paper); border-top:0; padding:12px 16px 14px; }
    .ix-panel .rec { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; padding:4px 10px; border-radius:20px; }
    .ix-panel .rec.wait { background:#E6EEF4; color:#2B5C7A; } .ix-panel .rec.rework { background:#FBEFD9; color:#B5731A; } .ix-panel .rec.drop { background:#FCE7E2; color:#B5341A; }
    .ix-panel .facts { display:flex; gap:18px; flex-wrap:wrap; font-size:12px; color:var(--ink-soft); margin:8px 0; }
    .ix-panel .facts b { color:var(--ink); font-weight:600; }
    .ix-panel .say { font-size:13px; color:var(--ink); max-width:70ch; line-height:1.45; }
    .ix-panel .acts { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .ix-panel .acts a, .ix-panel .acts button { font-size:12px; font-weight:600; border:1px solid var(--line); background:#fff; border-radius:8px; padding:5px 10px; color:var(--ink); text-decoration:none; cursor:pointer; }
    .ix-panel .acts .danger { color:#B5341A; }
</style>
<div class="ix-card ix-watch">
    <div class="ix-head">
        <div class="t">Waiting on Google <span style="color:var(--ink-soft);font-weight:600">— published pages not yet indexed</span></div>
        @php($ready = $watch['readiness'])
        @if ($ready['test_domain'])
            <div class="ix-readiness">
                <b>Test domain — nothing here can be indexed.</b> This site is on <code>{{ $ready['host'] }}</code>, a build host Google will never index.
                These pages start waiting on Google once the site moves to its real domain{{ $ready['connected'] ? '' : ' and Search Console is connected' }}. No data is expected until then.
            </div>
        @elseif (! $ready['connected'])
            <div class="ix-readiness">
                <b>Search Console is not connected — nothing here will be inspected.</b> Inspection verdicts and impressions both come from Search Console.
                Connect the Google account and pick this site's property under <a href="{{ \App\Filament\Resources\ConnectionsResource::getUrl('index') }}" wire:navigate>System → Connections</a>; until then the list only shows what was published.
            </div>
        @endif
        <div class="d">
            <b>{{ number_format($watch['waiting']) }}</b> published, not yet inspected ·
            <b style="color:#B5731A">{{ number_format($watch['inspected']) }}</b> inspected, not indexed ·
            <b style="color:#2E7D6B">{{ number_format($watch['landed']) }}</b> indexed in the last {{ $watch['watch_days'] }} days (they drop off after that)
        </div>
    </div>
    @php($m = $watch['metrics'])
    <div class="ix-nums">
        <div class="ix-num"><div class="n neutral">{{ number_format($m['published_week']) }}</div><div class="l">Published, past week</div></div>
        <div class="ix-num"><div class="n {{ $m['indexed_week'] > 0 ? 'good' : 'neutral' }}">{{ number_format($m['indexed_week']) }}</div><div class="l">Indexed, past week</div></div>
        <div class="ix-num"><div class="n {{ $m['not_indexed'] > 0 ? 'warn' : 'neutral' }}">{{ number_format($m['not_indexed']) }}</div><div class="l">Not indexed</div></div>
        <div class="ix-num"><div class="n {{ $m['stuck'] > 0 ? 'bad' : 'neutral' }}">{{ number_format($m['stuck']) }}</div><div class="l">Not indexed, over {{ $m['stuck_days'] }} days</div></div>
    </div>
    @if ($watch['rows'] === [])
        <div class="ix-none">{{ $ready['connected'] && ! $ready['test_domain'] ? 'Nothing waiting — every published page is indexed.' : 'Nothing on the list.' }}</div>
    @else
        <table>
            @php($arrow = fn (string $col): string => $watchSort === $col ? ($watchDir === 'asc' ? ' ▲' : ' ▼') : '')
            <thead><tr>
                <th>Page</th>
                <th><a class="ix-sort {{ $watchSort === 'published' ? 'on' : '' }}" href="{{ $this->sortUrl('published') }}">Published{{ $arrow('published') }}</a></th>
                <th><a class="ix-sort {{ $watchSort === 'inspected' ? 'on' : '' }}" href="{{ $this->sortUrl('inspected') }}">Inspected{{ $arrow('inspected') }}</a></th>
                <th><a class="ix-sort {{ $watchSort === 'status' ? 'on' : '' }}" href="{{ $this->sortUrl('status') }}">Status{{ $arrow('status') }}</a></th>
                <th><a class="ix-sort {{ $watchSort === 'indexed' ? 'on' : '' }}" href="{{ $this->sortUrl('indexed') }}">Indexed{{ $arrow('indexed') }}</a></th>
            </tr></thead>
            <tbody>
                @foreach ($watch['rows'] as $row)
                    <tr class="is-{{ $row['state'] }}" wire:key="ix-watch-{{ $row['content_id'] }}">
                        <td class="t">
                            @if ($row['url'])<a href="{{ $row['url'] }}" target="_blank" rel="noopener">{{ $row['title'] !== '' ? $row['title'] : $row['url'] }}</a>@else{{ $row['title'] }}@endif
                            <div class="k">{{ str_replace('_', ' ', $row['kind']) }}</div>
                            @if ($row['reason'])<div class="why">{{ $row['reason'] }}</div>@endif
                        </td>
                        <td class="date">{{ $row['published_at'] ? \Illuminate\Support\Carbon::parse($row['published_at'])->format('j M Y') : '—' }}@if ($row['state'] !== 'indexed' && $row['days_waiting'] !== null && $row['days_waiting'] > 0) <span class="k">· {{ $row['days_waiting'] }}d</span>@endif</td>
                        <td class="date">{{ $row['inspected_at'] ? \Illuminate\Support\Carbon::parse($row['inspected_at'])->format('j M') : '—' }}</td>
                        <td>
                            @if ($row['state'] === 'indexed')<x-lp.chip tone="good">Indexed</x-lp.chip>
                            @elseif ($row['state'] === 'inspected')<x-lp.chip tone="warn">Not indexed</x-lp.chip>
                            @else<span class="k">Published</span>@endif
                            @if ($row['state'] !== 'indexed' && ($row['days_waiting'] ?? 0) >= $stuckDays)
                                <a class="ix-why {{ $whyId === $row['content_id'] ? 'on' : '' }}" href="{{ $this->watchUrl(['why' => $whyId === $row['content_id'] ? null : $row['content_id']]) }}">{{ $whyId === $row['content_id'] ? 'Close' : 'Why?' }}</a>
                            @endif
                        </td>
                        <td class="date">{{ $row['indexed_at'] ? \Illuminate\Support\Carbon::parse($row['indexed_at'])->format('j M') : '—' }}@if ($row['state'] === 'indexed' && $row['days_waiting'] !== null) <span class="k">· {{ $row['days_waiting'] }}d to index</span>@endif</td>
                    </tr>
                    @if ($whyId === $row['content_id'] && ! isset($stuck[$row['content_id']]))
                        <tr class="ix-panel" wire:key="ix-why-{{ $row['content_id'] }}" @if ($stuckPending) wire:poll.5s @endif>
                            <td colspan="5">
                                @if ($stuckReport === null)
                                    <div class="say">
                                        <strong>Working out why…</strong> This maps every link on the site, which takes a minute on a large one. The answer appears here on its own.
                                        @unless ($stuckPending) <button type="button" class="ix-why" wire:click="refreshStuck">Start</button> @endunless
                                    </div>
                                @else
                                    <div class="say">This page is no longer in the stuck report (computed {{ \Illuminate\Support\Carbon::parse($stuckReport['computed_at'])->diffForHumans() }}) — it may have indexed since, or been re-published.
                                        <button type="button" class="ix-why" wire:click="refreshStuck">Recompute</button></div>
                                @endif
                            </td>
                        </tr>
                    @elseif ($whyId === $row['content_id'])
                        @php($s = $stuck[$row['content_id']])
                        <tr class="ix-panel" wire:key="ix-why-{{ $row['content_id'] }}">
                            <td colspan="5">
                                <span class="rec {{ $s['recommendation'] }}">{{ ['wait' => 'Wait — process lever', 'rework' => 'Rework the content', 'drop' => 'Drop it'][$s['recommendation']] ?? $s['recommendation'] }}</span>
                                <div class="facts">
                                    <span>Google says: <b>{{ $s['reason'] }}</b></span>
                                    <span>Waiting: <b>{{ $s['days_waiting'] ?? '—' }} days</b></span>
                                    <span>Pages linking to it: <b>{{ $s['inbound'] }}</b></span>
                                    <span>Search impressions ever: <b>{{ $s['impressions_ever'] ? 'yes' : 'none' }}</b></span>
                                    <span>IndexNow pinged: <b>{{ $s['indexnow_at'] ? \Illuminate\Support\Carbon::parse($s['indexnow_at'])->format('j M') : 'never' }}</b></span>
                                </div>
                                <div class="say">{{ $s['action'] }}.</div>
                                <div class="acts">
                                    @if ($s['lever'] === \App\Operator\Coverage\StuckPages::DROP)
                                        <button type="button" class="danger" wire:click="takeDownPost('{{ $row['content_id'] }}')" wire:confirm="Take this post down from WordPress? It goes back to Candidates and leaves this list.">Take down this post</button>
                                    @endif
                                    @if ($s['lever'] === \App\Operator\Coverage\StuckPages::REGENERATE || $s['lever'] === \App\Operator\Coverage\StuckPages::DROP)
                                        <a href="{{ $s['is_post'] ? \App\Filament\Pages\Operate\OperateBlog::getUrl() : \App\Filament\Pages\Operate\OperatePages::getUrl() }}" wire:navigate>Open in {{ $s['is_post'] ? 'Posts' : 'Pages' }} to regenerate</a>
                                    @endif
                                    @if ($s['lever'] === \App\Operator\Coverage\StuckPages::LINK)
                                        <a href="{{ \App\Filament\Pages\LocationCoverage::getUrl() }}" wire:navigate>Open the market link plan</a>
                                    @endif
                                    @if ($s['lever'] === \App\Operator\Coverage\StuckPages::RECHECK || $s['lever'] === \App\Operator\Coverage\StuckPages::PING)
                                        <span class="k" style="align-self:center">Use “Re-check indexing now” at the top of this page{{ $s['lever'] === \App\Operator\Coverage\StuckPages::PING ? ', then ping IndexNow (launchpad:boost-indexing)' : '' }}.</span>
                                    @endif
                                    @if ($s['url'])<a href="{{ $s['url'] }}" target="_blank" rel="noopener">View page ↗</a>@endif
                                    <span class="k" style="align-self:center">Diagnosis from {{ \Illuminate\Support\Carbon::parse($stuckReport['computed_at'])->diffForHumans() }} · <button type="button" class="ix-why" style="margin-left:0" wire:click="refreshStuck">Recompute</button></span>
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif
</div>
