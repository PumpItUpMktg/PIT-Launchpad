<x-lp.shell
    variant="table"
    eyebrow="System"
    title="Queue"
    lede="What the background workers are doing right now, in their own words. Each worker reports a heartbeat, the job it is on, and how it stopped if it is gone. Refreshes every ten seconds."
    :scope="false">

    @php($h = $this->health)
    @php($fmtAgo = fn (int $s): string => $s < 60 ? $s.'s ago' : ($s < 3600 ? intdiv($s, 60).'m ago' : intdiv($s, 3600).'h '.intdiv($s % 3600, 60).'m ago'))
    @php($fmtFor = fn (int $s): string => $s < 60 ? $s.'s' : intdiv($s, 60).'m '.($s % 60).'s')

    <style>
        .qb-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
        .qb-stat { background:var(--card); border:1px solid var(--line); border-radius:11px; padding:12px 16px; min-width:120px; }
        .qb-stat .n { font-family:'Spline Sans Mono',monospace; font-size:22px; font-weight:600; color:var(--teal-deep); }
        .qb-stat .n.bad { color:#B5341A; }
        .qb-stat .l { font-size:11px; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
        .qb-h { font-size:13px; font-weight:700; color:var(--ink); margin:18px 0 8px; }
        .qb-table { width:100%; border-collapse:collapse; font-size:13px; background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        .qb-table th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft); font-weight:700; padding:11px 14px; border-bottom:1px solid var(--line); background:var(--paper); }
        .qb-table td { padding:11px 14px; border-bottom:1px solid var(--line); vertical-align:top; }
        .qb-table tr:last-child td { border-bottom:0; }
        .qb-mono { font-family:'Spline Sans Mono',monospace; font-size:12.5px; }
        .qb-soft { color:var(--ink-soft); font-size:12px; }
        .qb-pill { display:inline-block; font-size:11px; font-weight:700; border-radius:999px; padding:2px 9px; border:1px solid transparent; }
        .qb-pill.ok { background:rgba(16,185,129,.1); color:#047857; border-color:rgba(16,185,129,.3); }
        .qb-pill.work { background:rgba(37,99,235,.08); color:#1d4ed8; border-color:rgba(37,99,235,.3); }
        .qb-pill.warn { background:rgba(245,158,11,.1); color:#b45309; border-color:rgba(245,158,11,.35); }
        .qb-pill.bad { background:rgba(220,38,38,.07); color:#b91c1c; border-color:rgba(220,38,38,.35); }
        .qb-pill.off { background:var(--paper); color:var(--ink-soft); border-color:var(--line); }
        .qb-alert { border:1px solid rgba(220,38,38,.4); background:rgba(220,38,38,.05); border-radius:12px; padding:12px 16px; margin-bottom:14px; font-size:13px; color:#b91c1c; }
        .qb-alert code, .qb-note code { font-family:'Spline Sans Mono',monospace; font-size:12px; background:var(--paper); padding:1px 5px; border-radius:5px; color:var(--ink); }
        .qb-note { font-size:12.5px; color:var(--ink-soft); margin-top:10px; line-height:1.5; }
        .qb-btn { font-size:12px; font-weight:600; background:none; border:1px solid var(--line); border-radius:8px; padding:5px 11px; cursor:pointer; color:var(--ink); }
        .qb-btn:hover { border-color:var(--teal-deep); }
        .qb-fail { display:flex; gap:10px; flex-wrap:wrap; align-items:baseline; font-size:12.5px; padding:6px 0; border-top:1px solid var(--line); }
        .qb-fail:first-child { border-top:0; }
    </style>

    <div wire:poll.10s>
        @if ($h['worker_down'])
            <div class="qb-alert">
                ⚠ No live worker on
                @foreach ($h['silent_lanes'] as $lane)<code>{{ $lane }}</code>@if (! $loop->last), @endif @endforeach
                — <b>{{ $h['pending'] }}</b> job(s) are waiting{{ $h['oldest_minutes'] > 0 ? ', the oldest for '.$h['oldest_minutes'].' minutes' : '' }}. The worker rows below say whether a process stopped (and why) or simply went silent.
            </div>
        @endif

        @if ($h['maintenance'])
            <div class="qb-alert">
                ⚠ The app is in <b>maintenance mode</b>. Every <code>queue:work</code> daemon pauses while it is down — the workers below keep heartbeating and consume nothing, however healthy they look. Bring the app up (<code>php artisan up</code>) to let the backlog drain.
            </div>
        @endif

        @php($blind = array_values(array_filter($h['workers'], fn (array $w): bool => $w['alive'] && ! $w['connection_ok'])))
        @if ($blind !== [])
            <div class="qb-alert">
                ⚠ {{ count($blind) }} live worker(s) are polling a queue connection that does not hold these jobs, so they run idle forever while the backlog sits:
                @foreach ($blind as $w)<code>{{ $w['worker_id'] }}</code> polls <code>{{ $w['connection'] !== '' ? $w['connection'] : 'unknown' }}</code>@if (! $loop->last), @endif @endforeach.
                This app enqueues on <code>{{ $h['connection'] }}</code> ({{ $h['driver'] }}). Start the worker against it — <code>php artisan queue:work {{ $h['connection'] }} --queue=high --tries=3</code> — or set <code>QUEUE_CONNECTION={{ $h['connection'] }}</code> in the worker's environment.
            </div>
        @endif

        <div class="qb-stats">
            <div class="qb-stat"><div class="n">{{ number_format($h['pending']) }}</div><div class="l">Waiting</div></div>
            <div class="qb-stat"><div class="n">{{ number_format(count(array_filter($h['workers'], fn (array $w): bool => $w['alive']))) }}</div><div class="l">Live workers</div></div>
            <div class="qb-stat"><div class="n {{ $h['failed'] > 0 ? 'bad' : '' }}">{{ number_format($h['failed']) }}</div><div class="l">Failed</div></div>
            <div class="qb-stat"><div class="n">{{ $h['oldest_minutes'] > 0 ? $h['oldest_minutes'].'m' : '—' }}</div><div class="l">Oldest waiting</div></div>
        </div>

        <div class="qb-h">Lanes</div>
        <table class="qb-table" aria-label="Queue lanes">
            <thead><tr><th>Lane</th><th>Waiting</th><th>In flight</th><th>Oldest</th><th>Worker</th><th>Status</th></tr></thead>
            <tbody>
            @foreach ($h['lanes'] as $lane)
                <tr>
                    <td class="qb-mono">{{ $lane['queue'] }}@if (! $lane['expected']) <span class="qb-soft">(unexpected)</span>@endif</td>
                    <td class="qb-mono">{{ $lane['pending'] }}</td>
                    <td class="qb-mono">{{ $lane['reserved'] }}</td>
                    <td class="qb-mono">{{ $lane['oldest_minutes'] > 0 ? $lane['oldest_minutes'].'m' : '—' }}</td>
                    <td class="qb-mono">{{ $lane['workers'] !== [] ? implode(', ', $lane['workers']) : '—' }}</td>
                    <td>
                        @if ($lane['busy'] !== null)
                            <span class="qb-pill work">Working {{ $lane['busy'] }}</span>
                        @elseif ($lane['alive'])
                            <span class="qb-pill ok">Live · idle</span>
                        @elseif ($lane['down'])
                            <span class="qb-pill bad">Down — jobs waiting, no worker</span>
                        @elseif ($lane['pending'] > 0)
                            <span class="qb-pill warn">No worker listening</span>
                        @else
                            <span class="qb-pill off">No worker · nothing waiting</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="qb-note">
            One process per lane keeps a slow lane from starving a fast one: <code>php artisan queue:work --queue=high --tries=3</code> and <code>php artisan queue:work --queue=default --tries=3</code>, each as its own background process, plus <code>php artisan schedule:work</code>. A lane that shows jobs and no listener needs its name added to a worker's <code>--queue</code> list; the metric-sync lanes fold into <code>default</code> with <code>LAUNCHPAD_METRICS_QUEUE=default</code>.
        </div>

        <div class="qb-h">Workers</div>
        @if ($h['workers'] === [])
            <div class="qb-soft" style="padding:12px 14px; background:var(--card); border:1px solid var(--line); border-radius:12px;">
                No worker has reported yet. Workers start reporting on their first loop after this build is deployed — restart the background processes if they were started before it.
            </div>
        @else
            <table class="qb-table" aria-label="Queue workers">
                <thead><tr><th>Process</th><th>Connection</th><th>Lanes</th><th>State</th><th>Last seen</th><th>Now</th><th>Done · failed</th><th>Memory</th><th>Started</th></tr></thead>
                <tbody>
                @foreach ($h['workers'] as $w)
                    <tr>
                        <td class="qb-mono">{{ $w['worker_id'] }}</td>
                        <td class="qb-mono">
                            {{ $w['connection'] !== '' ? $w['connection'] : '—' }}
                            @unless ($w['connection_ok'])<div class="qb-pill bad" style="margin-top:4px">wrong connection</div>@endunless
                        </td>
                        <td class="qb-mono">{{ $w['queues'] }}</td>
                        <td>
                            @if ($w['state'] === 'working')<span class="qb-pill work">Working</span>
                            @elseif ($w['state'] === 'idle')<span class="qb-pill ok">Live · idle</span>
                            @elseif ($w['state'] === 'stopped')<span class="qb-pill off">Stopped {{ $w['stopped_at'] }}</span><div class="qb-soft">{{ $w['stop_reason'] }}</div>
                            @else<span class="qb-pill bad">Silent</span><div class="qb-soft">No heartbeat and never stopped cleanly — the process was killed, or its host never restarted it.</div>
                            @endif
                        </td>
                        <td class="qb-mono">{{ $fmtAgo($w['seconds_since_seen']) }}</td>
                        <td>
                            @if ($w['current_job'] !== null)
                                <span class="qb-mono">{{ $w['current_job'] }}</span>
                                <div class="qb-soft">on {{ $w['current_queue'] }} for {{ $fmtFor((int) $w['job_seconds']) }}</div>
                            @else
                                <span class="qb-soft">—</span>
                            @endif
                        </td>
                        <td class="qb-mono">{{ $w['jobs_processed'] }} · {{ $w['jobs_failed'] }}</td>
                        <td class="qb-mono">{{ $w['memory_mb'] }} MB</td>
                        <td class="qb-mono">{{ $w['started_at'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        @if ($h['failed'] > 0)
            <div class="qb-h" style="display:flex; align-items:center; gap:12px;">
                Failed jobs
                <button type="button" class="qb-btn" wire:click="clearFailedJobs" wire:loading.attr="disabled" wire:target="clearFailedJobs"
                    wire:confirm="Clear {{ $h['failed'] }} failed job(s)? This removes the dead-job records (same as queue:flush). Fix the cause first so they don't recur.">Clear {{ $h['failed'] }} failed</button>
            </div>
            <div style="background:var(--card); border:1px solid var(--line); border-radius:12px; padding:6px 14px;">
                @foreach ($h['failures'] as $f)
                    <div class="qb-fail">
                        <span class="qb-mono" style="font-weight:700">{{ $f['job'] }}</span>
                        @if ($f['count'] > 1)<span class="qb-soft">×{{ $f['count'] }}</span>@endif
                        <span>{{ $f['reason'] }}</span>
                        <span class="qb-soft">last {{ $f['last'] }}</span>
                        @if ($f['pages'] !== [])<span class="qb-soft">{{ implode(', ', array_slice($f['pages'], 0, 4)) }}{{ count($f['pages']) > 4 ? ' +'.(count($f['pages']) - 4).' more' : '' }}</span>@endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-lp.shell>
