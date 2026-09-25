{{-- The Indexing board's watchlist block. Expects $this->watchlist, $watchSort, $watchDir. --}}
{{-- The watchlist: every published page not yet indexed, oldest first, then the ones that landed in
     the last few days. Plain = published, not yet inspected; amber = inspected, not indexed (with
     Google's reason); green = indexed, shown for `watch_days` days then gone. --}}
@php($watch = $this->watchlist)
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
    @if ($watch['rows'] === [])
        <div class="ix-none">{{ $ready['connected'] && ! $ready['test_domain'] ? 'Nothing waiting — every published page is indexed.' : 'Nothing on the list.' }}</div>
    @else
        <table>
            @php($arrow = fn (string $col): string => $watchSort === $col ? ($watchDir === 'asc' ? ' ▲' : ' ▼') : '')
            <thead><tr>
                <th>Page</th>
                <th><button type="button" class="ix-sort {{ $watchSort === 'published' ? 'on' : '' }}" wire:click="sortWatch('published')">Published{{ $arrow('published') }}</button></th>
                <th><button type="button" class="ix-sort {{ $watchSort === 'inspected' ? 'on' : '' }}" wire:click="sortWatch('inspected')">Inspected{{ $arrow('inspected') }}</button></th>
                <th><button type="button" class="ix-sort {{ $watchSort === 'status' ? 'on' : '' }}" wire:click="sortWatch('status')">Status{{ $arrow('status') }}</button></th>
                <th><button type="button" class="ix-sort {{ $watchSort === 'indexed' ? 'on' : '' }}" wire:click="sortWatch('indexed')">Indexed{{ $arrow('indexed') }}</button></th>
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
                        </td>
                        <td class="date">{{ $row['indexed_at'] ? \Illuminate\Support\Carbon::parse($row['indexed_at'])->format('j M') : '—' }}@if ($row['state'] === 'indexed' && $row['days_waiting'] !== null) <span class="k">· {{ $row['days_waiting'] }}d to index</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
