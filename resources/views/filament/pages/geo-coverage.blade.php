<x-filament-panels::page>
@php($report = $this->report)

<style>
    .gc { --gc-line:#e2e7ee; --gc-muted:#5a6675; --gc-faint:#8a95a3; --gc-surface2:#f6f8fb; }
    .dark .gc { --gc-line:#232c37; --gc-muted:#9aa7b5; --gc-faint:#6b7887; --gc-surface2:#0f151c; }
    .gc .gc-controls { display:flex; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
    .gc .gc-select { font-size:13px; border:1px solid var(--gc-line); border-radius:8px; padding:7px 12px; background:transparent; color:inherit; }
    .gc .gc-summary { display:flex; gap:22px; flex-wrap:wrap; font-size:13px; color:var(--gc-muted); margin-bottom:18px; }
    .gc .gc-summary b { color:inherit; font-weight:700; }
    .gc .gc-scroll { overflow-x:auto; }
    .gc table.gc-matrix { border-collapse:collapse; font-size:12.5px; }
    .gc .gc-matrix th, .gc .gc-matrix td { border:1px solid var(--gc-line); padding:7px 9px; text-align:center; white-space:nowrap; }
    .gc .gc-matrix th.gc-row, .gc .gc-matrix td.gc-row { text-align:left; font-weight:600; position:sticky; left:0; background:var(--gc-surface2); }
    .gc .gc-tier { font-size:9px; text-transform:uppercase; letter-spacing:.06em; color:var(--gc-faint); display:block; font-weight:700; }
    .gc .gc-cell { font-weight:700; font-variant-numeric:tabular-nums; }
    .gc .gc-strong { background:rgba(22,163,74,.16); color:#15803d; }
    .gc .gc-partial { background:rgba(217,119,6,.16); color:#b45309; }
    .gc .gc-weak { background:rgba(220,38,38,.14); color:#c0392b; }
    .gc .gc-pending { color:var(--gc-faint); }
    .gc .gc-untested { color:var(--gc-faint); background:repeating-linear-gradient(45deg,transparent,transparent 4px,rgba(148,163,184,.10) 4px,rgba(148,163,184,.10) 8px); }
    .gc .gc-legend { display:flex; gap:16px; flex-wrap:wrap; font-size:11.5px; color:var(--gc-muted); margin:10px 0 24px; }
    .gc .gc-legend i { display:inline-block; width:11px; height:11px; border-radius:3px; margin-right:5px; vertical-align:-1px; }
    .gc h3.gc-h { font-size:15px; font-weight:700; margin:8px 0 10px; }
    .gc .gc-gaps { width:100%; border-collapse:collapse; font-size:13px; }
    .gc .gc-gaps th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--gc-muted); font-weight:700; padding:0 10px 8px 0; border-bottom:1px solid var(--gc-line); }
    .gc .gc-gaps td { padding:9px 10px 9px 0; border-bottom:1px solid var(--gc-line); vertical-align:top; }
    .gc .gc-gaps .q { font-weight:600; }
    .gc .gc-comp { font-size:11.5px; color:#c0392b; }
    .gc .gc-note { margin-top:20px; font-size:12px; color:var(--gc-faint); }
    .gc .gc-empty { padding:40px 16px; text-align:center; color:var(--gc-muted); border:1px dashed var(--gc-line); border-radius:12px; }
</style>

<div class="gc">
    <div class="gc-controls">
        @if (count($this->locations) > 1)
            <select class="gc-select" wire:model.live="locationId" aria-label="Brick-and-mortar location">
                <option value="">All shops</option>
                @foreach ($this->locations as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
        <span style="font-size:12.5px;color:var(--gc-muted)">Observed AI-answer coverage — sampled, not a guarantee.</span>
    </div>

    @if ($report === null)
        <div class="gc-empty">Pick a tenant to see its AI-search coverage.</div>
    @elseif (empty($report['services']) || empty($report['columns']))
        <div class="gc-empty">No tagged prompts yet. Auto-seed prompts (Settings → AI Search) to build the coverage matrix.</div>
    @else
        <div class="gc-summary">
            <span><b>{{ $report['summary']['cited'] }}</b>/<b>{{ $report['summary']['measured'] }}</b> measured prompts cited</span>
            <span><b>{{ $report['summary']['prompts'] }}</b> prompts</span>
            <span><b>{{ $report['summary']['engines'] }}</b> engine(s)</span>
            <span><b>{{ $report['summary']['untested_cells'] }}</b> untested cell(s) — blind spots</span>
        </div>

        <div class="gc-scroll">
            <table class="gc-matrix">
                <thead>
                    <tr>
                        <th class="gc-row">Service</th>
                        @foreach ($report['columns'] as $col)
                            <th>{{ $col['name'] }}@if (! empty($col['tier']))<span class="gc-tier">{{ $col['tier'] }}</span>@endif</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['services'] as $svc)
                        <tr>
                            <td class="gc-row">{{ $svc['name'] }}</td>
                            @foreach ($report['columns'] as $col)
                                @php($cell = $report['cells'][$svc['id']][$col['key']] ?? null)
                                @if ($cell === null)
                                    <td class="gc-untested" title="Not asked yet — a blind spot">·</td>
                                @elseif ($cell['state'] === 'pending')
                                    <td class="gc-pending" title="Prompts exist but not measured yet">…</td>
                                @else
                                    <td class="gc-cell gc-{{ $cell['state'] }}" title="{{ $cell['cited'] }}/{{ $cell['measured'] }} prompts cited">{{ $cell['pct'] }}%</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="gc-legend">
            <span><i class="gc-strong"></i>Cited (strong)</span>
            <span><i class="gc-partial"></i>Partial</span>
            <span><i class="gc-weak"></i>Absent (weak)</span>
            <span><i class="gc-pending"></i>… measuring</span>
            <span><i class="gc-untested"></i>Blind spot (never asked)</span>
        </div>

        <h3 class="gc-h">Where you're absent</h3>
        @if (empty($report['gaps']))
            <p style="font-size:13px;color:var(--gc-muted)">No absent-gaps in measured prompts — you're cited everywhere that's been checked.</p>
        @else
            <table class="gc-gaps">
                <thead>
                    <tr><th>Prompt</th><th>Service · Town</th><th>Intent</th><th>Cited instead</th></tr>
                </thead>
                <tbody>
                    @foreach ($report['gaps'] as $gap)
                        <tr>
                            <td class="q">{{ $gap['prompt'] }}</td>
                            <td>{{ trim(($gap['service'] ?? '—').' · '.($gap['town'] ?? 'service-wide'), ' ·') }}</td>
                            <td>{{ $gap['intent'] ?? '—' }}</td>
                            <td class="gc-comp">{{ empty($gap['competitors']) ? '—' : implode(', ', $gap['competitors']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p class="gc-note">Every reading is one sampled AI answer per engine — trend it, don't treat a single cell as ground truth.</p>
    @endif

    {{-- Coverage-check accuracy — a separate lane from the visibility matrix, shown whenever coverage prompts exist. --}}
    @if ($this->verification !== null)
        @php($ver = $this->verification)
        <h3 class="gc-h" style="margin-top:28px;">Coverage accuracy — does the AI know this shop serves these towns?</h3>
        <div class="gc-summary">
            <span><b>{{ $ver['summary']['confirmed'] }}</b> confirmed</span>
            <span><b>{{ $ver['summary']['unaware'] }}</b> unaware</span>
            <span><b>{{ $ver['summary']['negative'] }}</b> negative</span>
            <span><b>{{ $ver['summary']['unknown'] }}</b> not yet checked</span>
        </div>
        <table class="gc-gaps">
            <thead>
                <tr><th>Service · Town</th><th>Does the AI know them here?</th></tr>
            </thead>
            <tbody>
                @foreach ($ver['rows'] as $row)
                    @php($v = $row['verdict'])
                    <tr>
                        <td>{{ trim(($row['service'] ?? '—').' · '.($row['town'] ?? '—'), ' ·') }}</td>
                        <td>
                            @if ($v === 'confirmed')
                                <span style="color:#15803d;font-weight:700;">Confirmed</span>
                            @elseif ($v === 'unaware')
                                <span style="color:#c0392b;font-weight:700;">Unaware — fix listing/schema</span>
                            @elseif ($v === 'negative')
                                <span style="color:#b45309;font-weight:700;">Negative mention</span>
                            @else
                                <span style="color:var(--gc-faint);">Not yet checked</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="gc-note">Coverage checks name the business, so they measure what the AI KNOWS about you — not competitive visibility. "Unaware" means the AI didn't confirm you serve that town; the fix is your listing / service-area page / schema, not a blog post.</p>
    @endif
</div>
</x-filament-panels::page>
