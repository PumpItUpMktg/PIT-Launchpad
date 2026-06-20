{{-- One inventory row: name + type + primary keyword + the folded sections it covers. --}}
<div class="lp-prow {{ $child ? 'child' : '' }}{{ $row['type'] === 'sub-hub' ? ' subhub' : '' }}" style="grid-template-columns:1fr auto">
    <div>
        <span class="pnm">{{ $row['type'] === 'sub-hub' ? '↳ ' : '' }}{{ $row['name'] }}</span>
        <div style="font-size:11.5px;color:var(--ink-soft)">{{ $row['keyword'] }}</div>
        @if (! empty($row['covers']))
            <div style="font-size:11px;color:var(--ungrouped)">covers: {{ implode(', ', $row['covers']) }}</div>
        @endif
    </div>
    <span class="lp-tag {{ $row['type'] === 'page' ? 'own' : 'hub' }}">{{ $row['type'] === 'page' ? 'Page' : ($row['type'] === 'sub-hub' ? 'Sub-hub' : 'Hub') }}</span>
</div>
