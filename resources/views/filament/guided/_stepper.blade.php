{{-- The left rail: the four setup steps (done / active / locked) + the Grow item, driven by
     SetupState through StepGate. Locked steps render as non-links. --}}
<aside class="lp-rail">
    <div class="lp-brand"><span class="dot"></span>Launchpad</div>
    @php
        // "Add a new site" until the business name is known, then personalize.
        $named = $brand && $brand !== 'your business';
        $railsub = $grow ? 'Grow — '.$brand : ($named ? 'Setting up '.$brand : 'Add a new site');
    @endphp
    <div class="lp-railsub">{{ $railsub }}</div>

    <div class="lp-steps">
        @foreach ($steps as $row)
            @php $s = $row['step']; @endphp
            @continue($s->isPhase())
            @php $cls = $row['active'] ? 'active' : ($row['done'] ? 'done' : ($row['locked'] ? 'locked' : '')); @endphp
            @if ($row['url'] && ! $row['active'])
                <a class="lp-step {{ $cls }}" href="{{ $row['url'] }}" wire:navigate>
                    <div class="num">{{ $row['done'] ? '✓' : $s->value }}</div>
                    <div><div class="lbl">{{ $s->label() }}</div><div class="lbl-sub">{{ $s->sublabel() }}</div></div>
                    <div class="conn"></div>
                </a>
            @else
                <div class="lp-step {{ $cls }}">
                    <div class="num">{{ $row['done'] ? '✓' : $s->value }}</div>
                    <div><div class="lbl">{{ $s->label() }}</div><div class="lbl-sub">{{ $s->sublabel() }}</div></div>
                    <div class="conn"></div>
                </div>
            @endif
        @endforeach
    </div>

    <div class="lp-railfoot">
        @foreach ($steps as $row)
            @php $s = $row['step']; @endphp
            @continue(! $s->isPhase())
            @php
                $cls = $row['active'] ? 'active' : ($row['done'] ? 'done' : ($row['locked'] ? 'locked' : ''));
                $icon = $s === \App\Enums\SetupStep::Build ? '⚒' : '◳';
            @endphp
            @if ($row['url'] && ! $row['active'])
                <a class="lp-grow {{ $cls }}" href="{{ $row['url'] }}" wire:navigate>
                    <div class="ic">{{ $icon }}</div><div><div class="lbl" style="color:inherit">{{ $s->label() }}</div></div>
                </a>
            @else
                <div class="lp-grow {{ $cls }}">
                    <div class="ic">{{ $icon }}</div><div><div class="lbl" style="color:inherit">{{ $s->label() }}</div></div>
                </div>
            @endif
        @endforeach
    </div>
</aside>
