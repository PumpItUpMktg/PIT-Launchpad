<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'The build trigger: a red-until-green checklist over everything gathering and generation produced, the build configuration, then Launch — pages materialize instantly and are generated one at a time from the Operate boards.'])

        @if ($this->launched)
            <div class="g-card" style="border-color:rgba(22,163,74,.4); background:rgba(22,163,74,.06)">
                <h3>Launched</h3>
                <p class="g-hint">The plan is materialized and the site is active. Re-run the build after a structure change (new service line → re-generate, re-prune, re-launch) — it re-materializes idempotently.</p>
            </div>
        @endif

        {{-- ── Readiness checklist ── --}}
        <div class="g-card">
            <h3>Readiness</h3>
            <div class="g-list">
                @foreach ($this->checklist as $item)
                    <div class="g-item" wire:key="lc-{{ $item['key'] }}">
                        <span class="g-seed {{ $item['ok'] ? 'confirmed' : '' }}" @style(['background:rgba(220,38,38,.12); color:#dc2626' => ! $item['ok'] && $item['required'] && ! $item['launch_ok']])>
                            {{ $item['ok'] ? '✓' : ($item['required'] && ! $item['launch_ok'] ? 'blocked' : 'open') }}
                        </span>
                        <strong>{{ $item['label'] }}</strong>
                        @if ($item['required'])<span class="g-muted">required</span>@endif
                        <span class="g-muted">{{ $item['detail'] }}</span>
                        @if (! $item['ok'] && $item['url'] !== null)
                            <a class="g-btn" style="margin-left:auto" href="{{ $item['url'] }}" wire:navigate>Fix →</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Standard pages ── --}}
        @if ($this->standardPages !== [])
            <div class="g-card">
                <h3>Standard pages</h3>
                <p class="g-hint">Optional foundation pages in the build — everything starts selected; curate by deselecting.</p>
                <div class="g-list">
                    @foreach ($this->standardPages as $row)
                        <div class="g-item" wire:key="sp-{{ $row['type']->value }}">
                            <input type="checkbox" @checked($row['accepted']) wire:click="toggleStandard('{{ $row['type']->value }}')">
                            <span>{{ $row['type']->label() }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Build config + Launch ── --}}
        <div class="g-card">
            <h3>Build configuration</h3>
            <div class="g-grid2">
                <div class="g-field">
                    <label><input type="checkbox" wire:model="localize"> Localize town pages</label>
                    <div class="g-hint">Ground each town page in local detail (county, landmarks) instead of a templated swap.</div>
                </div>
                <div class="g-field">
                    <label>Town pages per week</label>
                    <input class="g-input" type="number" min="1" wire:model="townPagePace">
                </div>
                <div class="g-field">
                    <label><input type="checkbox" wire:model="freshContent"> Fresh content engine</label>
                    <div class="g-hint">Keep the news→blog pipeline feeding this site after launch.</div>
                </div>
            </div>
            <button class="g-btn primary" wire:click="launch" wire:loading.attr="disabled"
                @if ($this->launched) wire:confirm="Re-run the build? The manifest re-assembles and pages re-materialize idempotently — nothing already generated is lost." @endif>
                <span wire:loading.remove wire:target="launch">{{ $this->launched ? '↻ Re-run build' : '🚀 Launch' }}</span>
                <span wire:loading wire:target="launch">Building…</span>
            </button>
        </div>
        @include('filament.gathering._next')
    </div>
</x-filament-panels::page>
