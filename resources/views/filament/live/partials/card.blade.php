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
<div class="lv-card" wire:key="lv-{{ $card['id'] }}">
    <div class="lv-top">
        <span class="lv-type">{{ ucfirst($card['type']) }}</span>
        <span class="lv-state">Live{{ $card['days_live'] !== null ? ' · '.$card['days_live'].'d' : '' }}</span>
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
            @if ($nav['featured'])
                <input type="number" min="1" class="lv-navorder" placeholder="order" value="{{ $nav['order'] }}"
                       wire:change="setNavOrder('{{ $card['id'] }}', $event.target.value)"
                       title="Menu order (lower shows first; blank = automatic)">
            @endif
        </div>
    @endif
</div>
