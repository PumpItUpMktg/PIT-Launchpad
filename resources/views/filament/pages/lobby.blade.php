<x-filament-panels::page>
    <style>
        .lb-controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
        .lb-search { flex:1; min-width:220px; font-size:13px; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:7px 11px; background:transparent; }
        .lb-seg { display:inline-flex; border:1px solid rgba(148,163,184,.35); border-radius:8px; overflow:hidden; }
        .lb-seg button { font-size:12px; padding:6px 12px; background:transparent; border:0; cursor:pointer; color:#64748b; }
        .lb-seg button.on { background:#4f46e5; color:#fff; }
        .lb-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:12px; }
        .lb-grid.rows { grid-template-columns:1fr; }
        .lb-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:14px 16px; cursor:pointer; display:flex; flex-direction:column; gap:10px; background:transparent; text-align:left; }
        .lb-card:hover { border-color:#4f46e5; }
        .lb-card.rows { flex-direction:row; align-items:center; gap:14px; }
        .lb-card.blocked { border-color:#dc2626; }
        .lb-head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
        .lb-name { font-size:14.5px; font-weight:650; margin:0; }
        .lb-domain { font-size:12px; color:#94a3b8; margin:2px 0 0; }
        .lb-pill { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:3px 9px; border-radius:999px; white-space:nowrap; }
        .lb-pill.blocked { background:rgba(220,38,38,.12); color:#dc2626; }
        .lb-pill.active { background:rgba(22,163,74,.12); color:#16a34a; }
        .lb-badges { display:flex; flex-wrap:wrap; gap:6px; }
        .lb-badge { display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:600; padding:4px 10px; border-radius:999px; border:0; cursor:pointer; }
        .lb-badge.danger { background:rgba(220,38,38,.12); color:#b91c1c; }
        .lb-badge.warning { background:rgba(217,119,6,.13); color:#b45309; }
        .lb-badge.gray { background:rgba(148,163,184,.16); color:#475569; }
        .lb-badge .n { font-weight:800; }
        .lb-more { font-size:11.5px; color:#64748b; background:transparent; border:0; cursor:pointer; padding:2px 0; text-align:left; }
        .lb-progress { height:7px; border-radius:999px; background:rgba(148,163,184,.2); overflow:hidden; }
        .lb-progress > span { display:block; height:100%; background:#4f46e5; }
        .lb-sub { font-size:12px; color:#64748b; }
        .lb-clean { border:1px dashed rgba(148,163,184,.4); border-radius:12px; padding:12px 16px; margin-top:14px; }
        .lb-cleanrow { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
        .lb-chip { font-size:12px; padding:4px 11px; border-radius:999px; border:1px solid rgba(148,163,184,.35); background:transparent; cursor:pointer; }
        .lb-empty { color:#94a3b8; font-size:13px; padding:24px; text-align:center; }
    </style>

    @php
        $cards = $this->cards;
        $clean = $cards->filter(fn ($c) => $c->isClean())->values();
        $shown = $cards->filter(fn ($c) => ! $c->isClean())->values();
    @endphp

    <div class="lb-controls">
        <input class="lb-search" type="search" placeholder="Search brand or domain…" wire:model.live.debounce.300ms="search">
        <div class="lb-seg">
            @foreach (['all' => 'All', 'attention' => 'Needs attention', 'onboarding' => 'Onboarding'] as $val => $label)
                <button type="button" class="{{ $filter === $val ? 'on' : '' }}" wire:click="$set('filter', '{{ $val }}')">{{ $label }}</button>
            @endforeach
        </div>
        <div class="lb-seg">
            <button type="button" class="{{ $density === 'cards' ? 'on' : '' }}" wire:click="$set('density', 'cards')">Cards</button>
            <button type="button" class="{{ $density === 'rows' ? 'on' : '' }}" wire:click="$set('density', 'rows')">Rows</button>
        </div>
    </div>

    <div class="lb-grid {{ $density === 'rows' ? 'rows' : '' }}">
        @forelse ($shown as $card)
            <div wire:key="lb-{{ $card->site->id }}" class="lb-card {{ $density === 'rows' ? 'rows' : '' }} {{ $card->isBlocked() ? 'blocked' : '' }}"
                 wire:click="enter('{{ $card->site->id }}')" role="button">
                <div class="lb-head" style="flex:1">
                    <div>
                        <p class="lb-name">{{ $card->brandName() }}</p>
                        <p class="lb-domain">{{ $card->domain() ?: '—' }}</p>
                    </div>
                    @if ($card->isBlocked())
                        <span class="lb-pill blocked">Blocked</span>
                    @elseif ($card->isOnboarding())
                        <span class="lb-pill" style="background:rgba(79,70,229,.12);color:#4f46e5">Setup</span>
                    @else
                        <span class="lb-pill active">Active</span>
                    @endif
                </div>

                @if ($card->isOnboarding())
                    <div class="lb-progress"><span style="width:{{ $card->onboardingStepCount ? min(100, round($card->onboardingStep / $card->onboardingStepCount * 100)) : 0 }}%"></span></div>
                    <div class="lb-sub">Step {{ $card->onboardingStep }} of {{ $card->onboardingStepCount }} · Continue setup</div>
                @else
                    <div class="lb-badges">
                        @foreach ($card->visibleBadges() as $badge)
                            <button type="button" class="lb-badge {{ $badge->color() }}" wire:click.stop="enterBadge('{{ $card->site->id }}', '{{ $badge->key }}')">
                                {{ $badge->label }}@if ($badge->count !== null)<span class="n">{{ $badge->count }}</span>@endif
                                @if ($badge->detail)<span style="opacity:.7">· {{ $badge->detail }}</span>@endif
                            </button>
                        @endforeach
                    </div>
                    @if ($card->moreLabel())
                        <button type="button" class="lb-more" wire:click.stop="enter('{{ $card->site->id }}')">{{ $card->moreLabel() }}</button>
                    @endif
                @endif
            </div>
        @empty
            <div class="lb-empty">No tenants match.</div>
        @endforelse
    </div>

    @if ($clean->isNotEmpty() && $filter !== 'onboarding')
        <div class="lb-clean">
            <div class="lb-sub"><strong>Nothing waiting · {{ $clean->count() }} {{ \Illuminate\Support\Str::plural('site', $clean->count()) }}</strong></div>
            <div class="lb-cleanrow">
                @foreach ($clean as $card)
                    {{-- Near-identical brands (Gurus vs Today) are disambiguated by the domain, so pills carry both. --}}
                    <button type="button" class="lb-chip" wire:click="enter('{{ $card->site->id }}')">
                        {{ $card->brandName() }}@if ($card->domain())<span style="opacity:.6"> · {{ \Illuminate\Support\Str::after($card->domain(), '://') }}</span>@endif
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
