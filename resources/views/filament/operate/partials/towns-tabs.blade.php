@php
    // The Towns sub-navigation (Relay 3 · PR 5g): the coverage-lifecycle surfaces presented as one
    // tabbed "Towns" item. They are heavy, divergent pages (coverage editor, tier progression, link
    // plans), so the tabs navigate between them (a shared sub-nav) rather than cramming them into one
    // page-component. Active tab = the current route.
    //
    // The town/location PAGES board is NOT a tab here: it is its own routed surface
    // (OperateLocationPages, reached via the Pages board's Town family tab). A "Towns board" entry
    // here pointed at exactly that page — the Town tab by another name — so it was removed as a
    // duplicate. (Territory → Towns, the coverage editor, is "Service area" below and stays.)
    $townsTabs = [
        ['label' => 'Service area', 'url' => \App\Filament\Pages\LocationsSetup::getUrl()],
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
<nav class="lp-towns-tabs" aria-label="Service area">
    @foreach ($townsTabs as $t)
        @php $p = rtrim(parse_url($t['url'], PHP_URL_PATH) ?? '', '/'); $on = $p !== '' && ($townsCurrent === $p || str_starts_with($townsCurrent, $p.'/')); @endphp
        <a href="{{ $t['url'] }}" wire:navigate class="lp-towns-tab {{ $on ? 'on' : '' }}">{{ $t['label'] }}</a>
    @endforeach
</nav>
