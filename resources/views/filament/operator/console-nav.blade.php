@php
    $columns = app(\App\Operator\Nav\ConsoleNav::class)->columns();
    $current = rtrim(request()->getPathInfo(), '/');
@endphp
{{-- The operator console header: a four-column IA (Build · Territory · Results · System), no
     dropdowns, 24 items. "Soon" items are greyed and non-clickable — the IA is complete and legible
     even before every surface has shipped. Source of truth: App\Operator\Nav\ConsoleNav. --}}
<style>
    .lp-console-nav { display:grid; grid-template-columns:repeat(4,1fr); gap:0; border-bottom:1px solid var(--gray-200,#e5e7eb); background:#fff; }
    .lp-cn-col { padding:12px 18px; border-right:1px solid var(--gray-100,#f1f5f9); min-width:0; }
    .lp-cn-col:last-child { border-right:0; }
    .lp-cn-group { font-size:10px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#94a3b8; margin:0 0 8px; }
    .lp-cn-items { display:flex; flex-wrap:wrap; gap:4px 14px; }
    .lp-cn-item { font-size:13px; font-weight:600; color:#334155; text-decoration:none; padding:2px 0; white-space:nowrap; border-bottom:2px solid transparent; }
    .lp-cn-item:hover { color:#b45309; }
    .lp-cn-item.is-active { color:#b45309; border-bottom-color:#f59e0b; }
    .lp-cn-soon { font-size:13px; font-weight:600; color:#cbd5e1; padding:2px 0; white-space:nowrap; cursor:default; display:inline-flex; align-items:center; gap:5px; }
    .lp-cn-soon .tag { font-size:8.5px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; color:#94a3b8; background:#f1f5f9; border-radius:8px; padding:1px 5px; }
    @media (prefers-color-scheme: dark) {
        .lp-console-nav { background:#0f151d; border-bottom-color:#1e293b; }
        .lp-cn-col { border-right-color:#182230; }
        .lp-cn-item { color:#cbd5e1; }
        .lp-cn-soon { color:#475569; }
        .lp-cn-soon .tag { color:#64748b; background:#1e293b; }
    }
</style>
<nav class="lp-console-nav" aria-label="Console navigation">
    @foreach ($columns as $col)
        <div class="lp-cn-col">
            <p class="lp-cn-group">{{ $col['group'] }}</p>
            <div class="lp-cn-items">
                @foreach ($col['items'] as $item)
                    @if ($item['soon'])
                        <span class="lp-cn-soon" title="Coming soon — ships in its own release">{{ $item['label'] }}<span class="tag">soon</span></span>
                    @else
                        @php
                            $path = rtrim(parse_url($item['url'], PHP_URL_PATH) ?? '', '/');
                            $active = $path !== '' && ($current === $path || str_starts_with($current, $path.'/'));
                        @endphp
                        <a href="{{ $item['url'] }}" wire:navigate class="lp-cn-item {{ $active ? 'is-active' : '' }}">{{ $item['label'] }}</a>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
</nav>
