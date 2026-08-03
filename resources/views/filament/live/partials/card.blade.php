{{-- One live-page card: identity → keyword line → metric grid (Position / GSC / GA4, each with an
     honest pending reason when its source or data is absent) → position sparkline → actions.
     Expects $card (LiveBoards::card shape). Optional $locationOptions marks an ORPHAN town card and
     adds the assign-location picker. --}}
@php
    $m = $card['metrics'];
    $pos = $m['position'];
    $gsc = $m['gsc'];
    $traffic = $m['traffic'];

    // Sparkline: ranks inverted (rank 1 = top of the box). Oldest → newest, max 24 points.
    $points = collect($m['series'])
        ->filter(fn ($p) => $p['rank'] !== null)
        ->sortBy('captured_at')->values()->take(-24);
    $spark = '';
    if ($points->count() >= 2) {
        $ranks = $points->pluck('rank');
        $min = max(1, (int) $ranks->min());
        $max = max($min + 1, (int) $ranks->max());
        $spark = $points->values()->map(function ($p, $i) use ($points, $min, $max) {
            $x = round($i / ($points->count() - 1) * 200, 1);
            $y = round(4 + (($p['rank'] - $min) / ($max - $min)) * 22, 1);
            return "{$x},{$y}";
        })->implode(' ');
    }
@endphp
@php $inGoogle = $gsc['in_google'] ?? false; @endphp
<div class="lv-card" wire:key="lv-{{ $card['id'] }}" @if ($inGoogle) style="box-shadow: inset 3px 0 0 #16a34a;" @endif>
    <div class="lv-top">
        <span class="lv-type">{{ ucfirst($card['type']) }}</span>
        <span class="lv-state">Live{{ $card['days_live'] !== null ? ' · '.$card['days_live'].'d' : '' }}</span>
        {{-- Indexing state: a page with Search Console impressions is definitely in Google's index and
             appearing. We only ever show the positive — no false "not indexed" for a young page. --}}
        @if ($inGoogle)
            <span title="Appearing in Google Search — this page is indexed."
                  style="font-size:10px; font-weight:700; color:#166534; background:rgba(22,163,74,.14); border:1px solid rgba(22,163,74,.35); padding:1px 8px; border-radius:99px;">✓ In Google</span>
        @endif
    </div>
    <div class="lv-id">
        <h3>{{ $card['title'] }}@if ($card['locked'])<span class="lv-lock" title="publishes never overwrite this page">locked</span>@endif</h3>
        <a href="{{ $card['url'] }}" target="_blank" rel="noopener">{{ $card['url'] }}</a>
        <div class="lv-dates">Published {{ $card['published_at'] ?? '—' }}</div>
    </div>
    <div class="lv-kw">
        Target:
        @if ($m['keyword'])
            <b>{{ $m['keyword'] }}</b>
        @else
            <span style="color:#94a3b8">—</span>
        @endif
        @if ($m['local']['rank'] !== null)
            <span class="lv-local">Local pack #{{ $m['local']['rank'] }}@if ($m['local']['market']) · {{ $m['local']['market'] }}@endif</span>
        @endif
    </div>
    <div class="lv-metrics">
        <div class="lv-m">
            <div class="k">Position</div>
            @if ($pos['rank'] !== null)
                <div class="v">#{{ $pos['rank'] }}</div>
                <div class="d">
                    @if ($pos['delta'] !== null && $pos['delta'] > 0)<span class="lv-up">▲ {{ $pos['delta'] }}</span> vs 30d
                    @elseif ($pos['delta'] !== null && $pos['delta'] < 0)<span class="lv-down">▼ {{ abs($pos['delta']) }}</span> vs 30d
                    @else steady @endif
                </div>
            @else
                <div class="lv-pending">{{ $pos['pending'] ?? 'Pending' }}</div>
            @endif
        </div>
        <div class="lv-m">
            <div class="k">GSC · 28d</div>
            @if ($gsc['impressions'] !== null)
                <div class="v">{{ number_format($gsc['impressions']) }}</div>
                <div class="d">impr · <b>{{ number_format((int) $gsc['clicks']) }}</b> clicks · {{ $gsc['ctr'] }}% CTR</div>
            @else
                <div class="lv-pending">{{ $gsc['pending'] }}</div>
            @endif
        </div>
        <div class="lv-m">
            <div class="k">GA4 sessions</div>
            @if ($traffic['sessions'] !== null)
                <div class="v">{{ number_format($traffic['sessions']) }}</div>
                <div class="d">28d</div>
            @else
                <div class="lv-pending">{{ $traffic['pending'] }}</div>
            @endif
        </div>
    </div>
    @if ($spark !== '')
        <div class="lv-spark">
            <svg viewBox="0 0 200 30" preserveAspectRatio="none" role="img" aria-label="position trend">
                <polyline points="{{ $spark }}" fill="none" stroke="#4f46e5" stroke-width="1.8"/>
            </svg>
            <span class="cap">position{{ $m['refresh_count'] > 0 ? ' · '.$m['refresh_count'].' refresh'.($m['refresh_count'] === 1 ? '' : 'es') : '' }}</span>
        </div>
    @endif
    {{-- The long tail this page is actually found for (Search Console — every "sump pump {city}" /
         "near me" variant it earned an impression on). This is where a location page's geo terms show. --}}
    @if (($gsc['queries'] ?? []) !== [])
        <div class="lv-queries" style="margin-top:8px;">
            <div class="k" style="font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin-bottom:4px;">Found in search for</div>
            <div style="display:flex; flex-wrap:wrap; gap:5px;">
                @foreach (array_slice($gsc['queries'], 0, 6) as $qr)
                    <span title="{{ number_format($qr['impressions']) }} impressions · {{ number_format($qr['clicks']) }} clicks · avg #{{ $qr['position'] }}"
                          style="font-size:11px; color:#334155; background:rgba(148,163,184,.14); border:1px solid rgba(148,163,184,.28); border-radius:99px; padding:2px 9px;">
                        {{ $qr['query'] }} <span style="color:#94a3b8;">#{{ $qr['position'] }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif
    <div class="lv-actions">
        {{-- The per-page QA drill-down: correct copy in place, replace images, WP preview. --}}
        <a class="lv-btn" href="{{ \App\Filament\Pages\ProofEditor::getUrl(['content' => $card['id']]) }}" wire:navigate>Review</a>
        <button type="button" class="lv-btn primary" wire:click="repush('{{ $card['id'] }}')">Repush</button>
        <button type="button" class="lv-btn" wire:click="regenerate('{{ $card['id'] }}')">Regenerate</button>
        <button type="button" class="lv-btn danger" wire:click="takeDown('{{ $card['id'] }}')" wire:confirm="Remove this page from WordPress? It stays in your plan and can be republished on the same URL.">Take down</button>
        @if (($locationOptions ?? []) !== [])
            <select class="lv-select" wire:change="assignLocation('{{ $card['id'] }}', $event.target.value)" title="assign this town to a location">
                <option value="">assign location…</option>
                @foreach ($locationOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        @endif
    </div>
    {{-- Priority-city toggle (opt-in via navControl, only the Operate pages boards define
         toggleCityPriority). Only city pages carry a market, so the control shows for those alone.
         Promoting a city assigns its "{service} {city}" tracking keywords (§5 Phase 2); the highlight
         reflects current tier. --}}
    @if (($navControl ?? false) && ($card['market_priority'] ?? null) !== null)
        <div class="lv-cityctl" style="margin-top:6px;">
            <button type="button" wire:click="toggleCityPriority('{{ $card['id'] }}')" wire:loading.attr="disabled"
                    title="{{ $card['market_priority'] ? 'A priority city — tracked in DataForSEO. Click to set back to coverage.' : 'Mark this city priority to track its rankings (uses DataForSEO credits on the next pull).' }}"
                    style="font-size:11px; font-weight:600; padding:3px 10px; border-radius:99px; cursor:pointer;
                           {{ $card['market_priority']
                               ? 'color:#92400e; background:rgba(217,119,6,.15); border:1px solid rgba(217,119,6,.4);'
                               : 'color:#64748b; background:transparent; border:1px solid rgba(148,163,184,.4);' }}">
                {{ $card['market_priority'] ? '★ Priority city' : '☆ Mark priority' }}
            </button>
        </div>
    @endif
    {{-- Header-menu curation (opt-in: only the Operate pages boards pass navControl, and only they
         define toggleNavFeatured/setNavOrder + the navState property). Check a page to pin it into the
         site header's main menu; the order number sorts it. Changes go live on the next Sync header &
         footer push. --}}
    @if ($navControl ?? false)
        @php $nav = $this->navState[$card['id']] ?? ['featured' => false, 'order' => null]; @endphp
        <div class="lv-navctl">
            <label title="Show this page in the site header's main menu">
                <input type="checkbox" wire:click="toggleNavFeatured('{{ $card['id'] }}')" @checked($nav['featured'])>
                In header menu
            </label>
            <label class="lv-navorder-lbl" title="Menu order (lower shows first; blank = automatic). Check the box first.">
                order
                <input type="number" min="1" class="lv-navorder" placeholder="auto" value="{{ $nav['order'] }}"
                       wire:change="setNavOrder('{{ $card['id'] }}', $event.target.value)"
                       @disabled(! $nav['featured'])>
            </label>
        </div>
    @endif
</div>
