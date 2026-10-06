{{-- "Request today" (§ Indexing): Google has no API to request indexing for an ordinary page; the operator
     presses Request indexing in Search Console, about ten a day. This is the day's list — the most valuable
     pages Google has not crawled, each with its inspect link — and "Requested" stamps a page off the list. --}}
@php($rq = $this->requestQueue)
@if ($rq !== null && ($rq['rows'] !== [] || $rq['requested_today'] > 0))
<style>
    .ix-req table { width:100%; border-collapse:collapse; font-size:13px; }
    .ix-req td { padding:7px 8px; border-top:1px solid var(--line,#e5e7eb); vertical-align:middle; }
    .ix-req td.n { color:var(--ink-soft,#6b7280); font-variant-numeric:tabular-nums; width:28px; }
    .ix-req td.k { color:var(--ink-soft,#6b7280); font-size:11.5px; white-space:nowrap; }
    .ix-req td.act { text-align:right; white-space:nowrap; }
    .ix-req .act a, .ix-req .act button { font-size:12px; font-weight:600; border:1px solid var(--line,#e5e7eb); background:#fff; border-radius:8px; padding:5px 10px; color:var(--ink,#111827); text-decoration:none; cursor:pointer; margin-left:6px; }
    .ix-req .act a.primary { border-color:#2563eb; color:#2563eb; }
    .ix-req .sub { font-size:12px; color:var(--ink-soft,#6b7280); margin-top:4px; }
</style>
<div class="ix-card ix-req">
    <div class="ix-head">
        <div class="t">Request today <span style="color:var(--ink-soft);font-weight:600">— ask Google for the {{ $rq['limit'] }} that matter most</span></div>
        <div class="sub">
            Google has no way for us to request indexing by API; the Request-indexing button after inspecting a URL in Search Console is the lever, about {{ $rq['quota'] }} a day per property.
            This is the order: service and hub pages, then towns biggest first, then posts longest-waiting — pages Google has not crawled, waiting {{ $rq['after_days'] }}+ days.
            <b>{{ $rq['requested_today'] }}</b> requested today · <b>{{ $rq['eligible'] }}</b> eligible
            @if (! $rq['connected']) · <span style="color:#B5341A">Search Console is not connected — the inspect links need the site's property</span>@endif
        </div>
    </div>
    @if ($rq['rows'] !== [])
        <table>
            <tbody>
                @foreach ($rq['rows'] as $i => $r)
                    <tr wire:key="ix-req-{{ $r['content_id'] }}">
                        <td class="n">{{ $i + 1 }}</td>
                        <td>
                            @if ($r['url'])<a href="{{ $r['url'] }}" target="_blank" rel="noopener">{{ $r['title'] }}</a>@else{{ $r['title'] }}@endif
                            <div class="k">{{ $r['kind'] }}{{ $r['population'] > 0 ? ' · pop '.number_format($r['population']) : '' }} · waiting {{ $r['days_waiting'] }} days{{ $r['requests'] > 0 ? ' · requested '.$r['requests'].'× before' : '' }}</div>
                        </td>
                        <td class="act">
                            @if ($r['inspect_url'])<a class="primary" href="{{ $r['inspect_url'] }}" target="_blank" rel="noopener">Inspect in Search Console ↗</a>@endif
                            <button type="button" wire:click="markRequested('{{ $r['content_id'] }}')" wire:loading.attr="disabled" wire:target="markRequested" title="Press after you clicked Request indexing in Search Console — it stamps the page and takes it off today's list">Mark requested</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="ix-none">Today's requests are in. The next pages come back as the cooldown passes or as more pages wait past {{ $rq['after_days'] }} days.</div>
    @endif
</div>
@endif
