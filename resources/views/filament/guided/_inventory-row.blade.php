{{-- One directed-coverage row: type tag + name + primary keyword, with the folded sections it covers. --}}
@php
    $tagClass = $row['type'] === 'hub' ? 'hub' : ($row['type'] === 'sub-hub' ? 'sub' : '');
    $tagLabel = $row['type'] === 'hub' ? 'Hub' : ($row['type'] === 'sub-hub' ? 'Sub-hub' : 'Page');
@endphp
<div class="lp-pagerow" @if ($child) style="padding-left:18px" @endif>
    <div class="prn">
        <span class="lp-ptag {{ $tagClass }}">{{ $tagLabel }}</span>
        {{ $row['name'] }}
        @if (! empty($row['keyword']))
            <span style="font-weight:400;color:var(--ink-soft);font-size:11.5px">· {{ $row['keyword'] }}</span>
        @endif
    </div>
    @if (! empty($row['covers']))
        <div class="prc">covers: {{ implode(', ', $row['covers']) }}</div>
    @endif
</div>
