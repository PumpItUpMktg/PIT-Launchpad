@php
    // The Towns sub-navigation (Relay 3 · PR 5g): the four coverage-lifecycle surfaces presented as
    // one tabbed "Towns" item. They are heavy, divergent pages (coverage editor, grouped board, tier
    // progression, link plans), so the tabs navigate between them (a shared sub-nav) rather than
    // cramming four page-components into one. Active tab = the current route.
    $townsTabs = [
        ['label' => 'Service area', 'url' => \App\Filament\Pages\LocationsSetup::getUrl()],
        ['label' => 'Towns board', 'url' => \App\Filament\Pages\Operate\OperateLocationPages::getUrl()],
        ['label' => 'Tier progression', 'url' => \App\Filament\Pages\Operate\OperateTierProgression::getUrl()],
        ['label' => 'Link plans', 'url' => \App\Filament\Pages\Operate\OperateLinkPlans::getUrl()],
    ];
    $townsCurrent = rtrim(request()->getPathInfo(), '/');
@endphp
<style>
    .lp-towns-tabs { display:flex; gap:2px; border-bottom:1px solid var(--line,#e5e7eb); margin:0 0 16px; flex-wrap:wrap; }
    .lp-towns-tab { padding:9px 15px; font-size:13.5px; font-weight:700; color:#64748b; text-decoration:none; border-bottom:2px solid transparent; }
    .lp-towns-tab:hover { color:#b45309; }
    .lp-towns-tab.on { color:#b45309; border-bottom-color:#f59e0b; }
</style>
<nav class="lp-towns-tabs" aria-label="Towns">
    @foreach ($townsTabs as $t)
        @php $p = rtrim(parse_url($t['url'], PHP_URL_PATH) ?? '', '/'); $on = $p !== '' && ($townsCurrent === $p || str_starts_with($townsCurrent, $p.'/')); @endphp
        <a href="{{ $t['url'] }}" wire:navigate class="lp-towns-tab {{ $on ? 'on' : '' }}">{{ $t['label'] }}</a>
    @endforeach
</nav>
