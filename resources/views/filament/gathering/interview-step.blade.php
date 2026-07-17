<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'Operator-led: you\'re on the call, the owner talks, you type their answers. The engine asks the next question and tracks what\'s covered. Entirely skippable — every later step is directly editable.'])

        <style>
            .gi-layout { display:grid; grid-template-columns: 1fr 240px; gap:14px; align-items:start; }
            @media (max-width: 900px) { .gi-layout { grid-template-columns: 1fr; } }
            .gi-chat { display:flex; flex-direction:column; gap:10px; }
            .gi-msgs { display:flex; flex-direction:column; gap:8px; max-height:52vh; overflow-y:auto; padding:4px 2px; }
            .gi-msg { max-width:78%; padding:9px 13px; border-radius:12px; font-size:13.5px; line-height:1.45; }
            .gi-msg.assistant { align-self:flex-start; background:rgba(79,70,229,.1); border:1px solid rgba(79,70,229,.25); }
            .gi-msg.operator { align-self:flex-end; background:rgba(148,163,184,.12); border:1px solid rgba(148,163,184,.3); }
            .gi-tag { display:block; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#818cf8; margin-bottom:2px; font-weight:700; }
            .gi-meter .row { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid rgba(148,163,184,.16); font-size:12.5px; }
            .gi-meter .row:last-child { border-bottom:0; }
            .gi-state { margin-left:auto; font-size:10px; font-weight:700; text-transform:uppercase; padding:1px 7px; border-radius:5px; }
            .gi-state.filled { background:rgba(22,163,74,.12); color:#16a34a; }
            .gi-state.thin { background:rgba(217,119,6,.13); color:#b45309; }
            .gi-state.empty { background:rgba(148,163,184,.15); color:#64748b; }
            .gi-skip { background:none; border:0; color:#94a3b8; font-size:11px; cursor:pointer; padding:0; }
        </style>

        @php $interview = $this->interview; @endphp

        @if ($interview === null)
            <div class="g-card">
                <h3>No interview yet</h3>
                <p class="g-hint">Start when you have the owner on the phone — the opener references what import already answered. Or skip entirely: Locations, Services and Voice are all directly editable.</p>
                <button class="g-btn primary" wire:click="begin">Start the interview</button>
            </div>
        @else
            <div class="gi-layout">
                <div class="g-card gi-chat">
                    <div class="g-row" style="justify-content:space-between">
                        <h3>Interview · {{ $interview->status->value }}</h3>
                        <div class="g-row">
                            <button class="g-btn" wire:click="extract">Extract now</button>
                            @if ($interview->status === \App\Enums\InterviewStatus::InProgress)
                                <button class="g-btn danger" wire:click="endInterview">End interview & extract</button>
                            @endif
                        </div>
                    </div>

                    <div class="gi-msgs" id="gi-msgs">
                        @foreach ($interview->turns as $turn)
                            <div class="gi-msg {{ $turn->role }}" wire:key="turn-{{ $turn->id }}">
                                @if ($turn->role === 'assistant' && $turn->section_tag !== null)
                                    <span class="gi-tag">{{ \App\Enums\InterviewSection::tryFrom($turn->section_tag)?->label() ?? $turn->section_tag }}</span>
                                @endif
                                {{ $turn->content }}
                            </div>
                        @endforeach
                    </div>

                    @if ($interview->status === \App\Enums\InterviewStatus::InProgress)
                        <div class="g-row" style="align-items:stretch">
                            <textarea class="g-textarea" rows="2" style="flex:1" placeholder="Type the owner's answer…"
                                wire:model="input" wire:keydown.enter.prevent="send"></textarea>
                            <button class="g-btn primary" wire:click="send" wire:loading.attr="disabled">Send</button>
                        </div>
                        <div class="g-row">
                            <input class="g-input" style="max-width:420px" type="text" placeholder="Off-script note (\"owner said X\")" wire:model="noteInput">
                            <button class="g-btn" wire:click="addNote">Add note</button>
                        </div>
                    @else
                        <p class="g-hint">Interview {{ $interview->status->value }} — the transcript is preserved; Extract re-runs anytime and never touches confirmed fields.</p>
                    @endif
                </div>

                <div class="g-card gi-meter">
                    <h3>Coverage</h3>
                    @foreach ($this->meter as $row)
                        <div class="row" wire:key="meter-{{ $row['section']->value }}">
                            <span>{{ $row['section']->label() }}</span>
                            <span class="gi-state {{ $row['state'] }}">{{ $row['state'] }}</span>
                            @if ($interview->status === \App\Enums\InterviewStatus::InProgress && $row['state'] !== 'filled')
                                <button class="gi-skip" title="Skip this section" wire:click="skipSection('{{ $row['section']->value }}')">skip</button>
                            @endif
                        </div>
                    @endforeach
                    @php
                        $states = collect($this->meter)->pluck('state');
                        $thin = $states->filter(fn ($s) => $s === 'thin')->count();
                        $allFilled = $states->every(fn ($s) => $s === 'filled');
                        $noneEmpty = $states->doesntContain('empty');
                    @endphp
                    @if ($interview->status === \App\Enums\InterviewStatus::InProgress && $allFilled)
                        <div style="border:1px solid rgba(22,163,74,.4); background:rgba(22,163,74,.07); border-radius:9px; padding:9px 12px; font-size:12.5px; color:#16a34a; font-weight:600">
                            ✓ Every section covered — you're done whenever you are. End interview &amp; extract.
                        </div>
                    @elseif ($interview->status === \App\Enums\InterviewStatus::InProgress && $noneEmpty)
                        <div style="border:1px solid rgba(217,119,6,.35); background:rgba(217,119,6,.06); border-radius:9px; padding:9px 12px; font-size:12.5px; color:#b45309">
                            Everything touched; {{ $thin }} section(s) still thin. Go deeper, skip them, or end now — thin just means lighter seeding, and every field stays editable on the review steps.
                        </div>
                    @endif
                    <p class="g-hint">The model's read of the transcript — it only ever moves up (a filled section never falls back), and filled sections won't be re-asked. Complete is YOUR call: End &amp; extract anytime; Extract re-runs safely and never touches confirmed fields.</p>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
