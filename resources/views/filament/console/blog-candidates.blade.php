<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')
    @include('filament.console.partials.blog-card-styles')

    <style>
        .bc-group { margin-top: 18px; }
        .bc-group:first-of-type { margin-top: 4px; }
        .bc-group-head { display:flex; align-items:baseline; gap:10px; padding:6px 2px; border-bottom:1px solid var(--line,#e5e7eb); margin-bottom:10px; }
        .bc-group-silo { font-size:14px; font-weight:800; color:#0f172a; }
        .bc-group-meta { font-size:12px; color:#64748b; }
        .bc-group-meta .local { color:#2E7D6B; font-weight:700; }
        .bc-more { font-size:12.5px; color:#64748b; padding:8px 2px 2px; font-style:italic; }
        @media (prefers-color-scheme: dark) { .bc-group-silo { color:#f1f5f9; } }
    </style>

    @php $groups = $this->candidateGroups; @endphp

    @forelse ($groups as $g)
        <div class="bc-group" wire:key="grp-{{ $loop->index }}">
            <div class="bc-group-head">
                <span class="bc-group-silo">{{ $g['silo'] }}</span>
                <span class="bc-group-meta">
                    {{ $g['total'] }} {{ \Illuminate\Support\Str::plural('candidate', $g['total']) }}
                    @if ($g['local'] > 0) · <span class="local">{{ $g['local'] }} local</span> @endif
                </span>
            </div>

            <div class="bc-list">
                @foreach ($g['visible'] as $c)
                    @include('filament.console.partials.blog-candidate-card', ['c' => $c])
                @endforeach
            </div>

            @if ($g['overflow'] > 0)
                <div class="bc-more">+{{ $g['overflow'] }} more in this silo — narrow with the score filter or open the silo to triage them.</div>
            @endif
        </div>
    @empty
        <div class="bc-empty">No candidates waiting. New ones arrive as feeds are ingested.</div>
    @endforelse
</x-filament-panels::page>
