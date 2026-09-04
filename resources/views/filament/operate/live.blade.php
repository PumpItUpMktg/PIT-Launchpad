<x-filament-panels::page>
    <style>
        .lv-tabs { display:flex; gap:2px; border-bottom:1px solid var(--line,#e5e7eb); margin-bottom:14px; flex-wrap:wrap; }
        .lv-tab { display:inline-flex; align-items:center; gap:7px; padding:9px 15px; font-size:13.5px; font-weight:700; color:#64748b; background:none; border:0; border-bottom:2px solid transparent; cursor:pointer; }
        .lv-tab:hover { color:#b45309; }
        .lv-tab.on { color:#b45309; border-bottom-color:#f59e0b; }
        .lv-tab .ct { font-size:11px; font-weight:800; color:#64748b; background:#eef1f4; border-radius:20px; padding:1px 7px; }
        .lv-tab.on .ct { background:#fbe4c9; color:#b45309; }
        .lv-filters { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:16px; }
        .lv-filters input[type=search], .lv-filters select { border:1px solid var(--line,#e5e7eb); border-radius:8px; padding:7px 11px; font-size:13px; background:#fff; }
        .lv-filters input[type=search] { min-width:240px; }
        .lv-chk { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#334155; font-weight:600; }
        .lv-list { display:flex; flex-direction:column; gap:10px; }
        .lv-act { font-size:12px; font-weight:700; border:1px solid var(--line,#e5e7eb); border-radius:8px; padding:6px 10px; background:#fff; color:#334155; text-decoration:none; cursor:pointer; }
        .lv-act:hover { border-color:#f59e0b; color:#b45309; }
        @media (prefers-color-scheme: dark) {
            .lv-filters input, .lv-filters select, .lv-act { background:#151b24; color:#cbd5e1; }
        }
    </style>

    @php $counts = $this->counts; @endphp

    {{-- Type selector — a filter over one dataset, not five views. All is the default. --}}
    <div class="lv-tabs" role="tablist">
        @foreach (['all' => 'All', 'blog' => 'Blog', 'core' => 'Core', 'service' => 'Service', 'town' => 'Town'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    class="lv-tab {{ $tab === $key ? 'on' : '' }}" wire:click="setTab('{{ $key }}')">
                {{ $label }} <span class="ct">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    {{-- Filter row --}}
    <div class="lv-filters">
        <input type="search" placeholder="Search title or keyword…" wire:model.live.debounce.400ms="search">
        @if ($this->marketOptions !== [])
            <select wire:model.live="market">
                <option value="">All markets</option>
                @foreach ($this->marketOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <label class="lv-chk"><input type="checkbox" wire:model.live="notIndexed"> Not indexed</label>
        <label class="lv-chk"><input type="checkbox" wire:model.live="notRanking"> Not ranking</label>
    </div>

    {{-- Cards — the ONE shared content-card component (App\Operate\LiveBoard rows). --}}
    <div class="lv-list">
        @forelse ($this->rows as $row)
            <x-lp.content-card :row="$row" wire:key="live-{{ $row['id'] }}">
                <x-slot:actions>
                    <button type="button" class="lv-act" wire:click="repush('{{ $row['id'] }}')" wire:confirm="Re-push this page to WordPress?">Repush</button>
                    <button type="button" class="lv-act" wire:click="takeDown('{{ $row['id'] }}')" wire:confirm="Take this page down from WordPress?">Take down</button>
                    @if ($row['wp_url']) <a class="lv-act" href="{{ $row['wp_url'] }}" target="_blank" rel="noopener">Open in WP</a> @endif
                </x-slot:actions>
            </x-lp.content-card>
        @empty
            <x-lp.empty title="Nothing live here yet" action="Go to Pages" :href="\App\Filament\Pages\Operate\OperatePages::getUrl()">
                Published pages and posts appear here with their tracking once they go live.
            </x-lp.empty>
        @endforelse
    </div>
</x-filament-panels::page>
