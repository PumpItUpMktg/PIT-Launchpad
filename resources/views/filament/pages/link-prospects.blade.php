<x-filament-panels::page>
@php
    $rows = $this->prospects;
    $summary = $this->summary;
    $statuses = \App\Enums\LinkProspectStatus::cases();
    $kindColor = fn (\App\Enums\LinkProspectKind $k): string => match ($k) {
        \App\Enums\LinkProspectKind::Directory => 'info',
        \App\Enums\LinkProspectKind::Local => 'success',
        \App\Enums\LinkProspectKind::Editorial => 'warning',
        default => 'gray',
    };
@endphp

    @if ($this->siteId === null)
        <x-filament::section>Choose a working tenant first.</x-filament::section>
    @else
        <div style="display:flex;flex-direction:column;gap:1rem">
            <x-filament::section>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                    <div style="font-size:.85rem;color:var(--gray-500)">
                        Domains that link to the local competitors Town Rank finds above you, and not to you — ranked by how many competitors they link to.
                        @if ($summary['count'] > 0)
                            <strong>{{ $summary['count'] }}</strong> on the list · last found {{ $summary['last_found_at']?->diffForHumans() ?? '—' }}
                            @foreach ($summary['by_status'] as $st => $n) · {{ \App\Enums\LinkProspectStatus::from($st)->label() }} {{ $n }} @endforeach
                        @else
                            No prospects yet — run the intersect once Town Rank has scanned.
                        @endif
                    </div>
                    <x-filament::button size="sm" wire:click="findProspects" wire:confirm="Run the competitor intersect for this site? One backlink-index call.">Find prospects</x-filament::button>
                </div>
                @php($run = $this->lastRun)
                @if ($run !== null)
                    <div style="margin-top:.75rem;padding:.6rem .75rem;border-radius:.5rem;background:var(--gray-50);font-size:.78rem;line-height:1.5">
                        <div><strong>Last run</strong> {{ $run->ran_at->diffForHumans() }}
                            @if ($run->status === \App\Models\LinkProspectRun::FAILED)
                                · <span style="color:#b91c1c">failed: {{ $run->error }}</span>
                            @else
                                · the index returned <strong>{{ $run->returned }}</strong> linking domains · <strong>{{ $run->kept }}</strong> kept ({{ $run->dropped_spam }} dropped as spam, {{ $run->dropped_rank }} below the authority floor) · {{ $run->new }} new, {{ $run->refreshed }} refreshed, {{ $run->pruned }} untouched from an earlier run dropped
                            @endif
                        </div>
                        <div style="color:var(--gray-500)">Competitors asked about:
                            @forelse ($run->targets as $t)
                                <span style="white-space:nowrap">{{ $t['domain'] }} <em>({{ $t['source'] === 'map_pack' ? 'map pack, '.$t['appearances'].' cells' : 'organic, above you in '.$t['appearances'].' town results' }})</em></span>@if (! $loop->last), @endif
                            @empty
                                none — Town Rank has not found a local competitor yet
                            @endforelse
                        </div>
                        @if ($run->excluded !== [])
                            <div style="color:var(--gray-500)">Excluded as territory-wide (not a local company):
                                @foreach ($run->excluded as $d)<span style="white-space:nowrap">{{ $d['domain'] }} <em>({{ (int) round($d['share'] * 100) }}% of one scan's towns)</em></span>@if (! $loop->last), @endif @endforeach
                            </div>
                        @endif
                    </div>
                @endif
                <div style="display:flex;gap:.4rem;margin-top:.75rem;flex-wrap:wrap">
                    @foreach (['open' => 'Open', 'new' => 'New', 'contacted' => 'Contacted', 'acquired' => 'Acquired', 'dismissed' => 'Dismissed', 'all' => 'All'] as $key => $label)
                        <x-filament::button size="xs" wire:click="$set('status', '{{ $key }}')" :color="$this->status === $key ? 'primary' : 'gray'">{{ $label }}</x-filament::button>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                    <thead>
                        <tr style="text-align:left;color:var(--gray-500);font-size:.72rem;text-transform:uppercase">
                            <th style="padding:.5rem">Domain</th><th>DR</th><th>Links to</th><th>Kind</th><th>Status</th><th>Notes</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $p)
                            <tr style="border-top:1px solid var(--gray-100)">
                                <td style="padding:.5rem;font-weight:500">{{ $p->domain }}</td>
                                <td style="color:var(--gray-500)">{{ $p->rank ?? '—' }}</td>
                                <td style="font-size:.78rem"><strong>{{ $p->competitor_count }}</strong> <span style="color:var(--gray-500)">{{ implode(', ', $p->competitors) }}</span></td>
                                <td><x-filament::badge :color="$kindColor($p->kind)">{{ $p->kind->label() }}</x-filament::badge></td>
                                <td>
                                    <select wire:change="setStatus('{{ $p->id }}', $event.target.value)" style="font-size:.8rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.2rem .4rem">
                                        @foreach ($statuses as $st)
                                            <option value="{{ $st->value }}" @selected($p->status === $st)>{{ $st->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="text" value="{{ $p->notes }}" placeholder="who, when, what they said" wire:change="setNotes('{{ $p->id }}', $event.target.value)" style="width:100%;font-size:.8rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.2rem .4rem"></td>
                                <td style="text-align:right;white-space:nowrap">
                                    <a href="https://{{ $p->domain }}" target="_blank" rel="noopener" style="font-size:.75rem;color:#2563eb;text-decoration:underline;margin-right:.5rem">Open ↗</a>
                                    <x-filament::button size="xs" color="gray" wire:click="openKit('{{ $p->id }}')">Pitch</x-filament::button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" style="padding:1rem;color:var(--gray-500)">Nothing in this view.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        @php($ak = $this->activeKit)
        @if ($ak !== null)
            <div wire:click="closeKit" style="position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:40"></div>
            <aside style="position:fixed;top:0;right:0;bottom:0;width:min(560px,100%);background:var(--gray-50);z-index:41;overflow:auto;box-shadow:-8px 0 24px rgba(0,0,0,.1)">
                <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start">
                        <div>
                            <h2 style="font-size:1.1rem;font-weight:600">{{ $ak['prospect']->domain }}</h2>
                            <div style="font-size:.78rem;color:var(--gray-500)">{{ $ak['prospect']->kind->label() }} · links to {{ implode(', ', $ak['prospect']->competitors) }}</div>
                        </div>
                        <button wire:click="closeKit" style="border:0;background:none;font-size:1.4rem;cursor:pointer;color:var(--gray-500)">&times;</button>
                    </div>
                    <label style="font-size:.8rem;display:block"><span style="color:var(--gray-500)">Speak for</span>
                        <select wire:model.live="kitLocationId" style="width:100%;font-size:.8rem;border:1px solid var(--gray-300);border-radius:.375rem;padding:.3rem .4rem">
                            <option value="">The brand (links to the home page)</option>
                            @foreach ($this->offices as $o)<option value="{{ $o['id'] }}">{{ $o['name'] }} office</option>@endforeach
                        </select>
                    </label>
                    @php($kit = $ak['kit'])
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500)">Pitch</h3>
                        <x-filament::button size="xs" :color="$kit ? 'gray' : 'primary'" wire:click="draftKit({{ $kit ? 'true' : 'false' }})">{{ $kit ? 'Redraft' : 'Draft pitch' }}</x-filament::button>
                    </div>
                    @if ($kit)
                        @php($k = $kit->payload)
                        @if (! $kit->model_ok)<p style="font-size:.75rem;color:#b45309">Plain fallback copy — the model did not answer. Redraft to try again.</p>@endif
                        <div style="display:flex;flex-direction:column;gap:.5rem;font-size:.8rem">
                            <label style="display:block"><span style="color:var(--gray-500)">Subject</span><input readonly value="{{ $k['pitch_subject'] ?? '' }}" onclick="this.select()" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff"></label>
                            <label style="display:block"><span style="color:var(--gray-500)">Email</span><textarea readonly rows="12" onclick="this.select()" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff">{{ $k['pitch_body'] ?? '' }}</textarea></label>
                            <label style="display:block"><span style="color:var(--gray-500)">Link to ask for</span><input readonly value="{{ $k['link_url'] ?? '' }}" onclick="this.select()" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff"></label>
                            <label style="display:block"><span style="color:var(--gray-500)">One-line description (for a listing or a bio)</span><textarea readonly rows="2" onclick="this.select()" style="width:100%;border:1px solid var(--gray-200);border-radius:.375rem;padding:.3rem .5rem;background:#fff">{{ $k['short_description'] ?? '' }}</textarea></label>
                        </div>
                        <p style="font-size:.7rem;color:var(--gray-400)">Drafted {{ $kit->drafted_at->diffForHumans() }}. Click a field to select it. Nothing is sent from here.</p>
                    @else
                        <p style="font-size:.8rem;color:var(--gray-400)">A short email asking for one specific link, in your voice, with the page to link — ready to paste into your mail client.</p>
                    @endif
                </div>
            </aside>
        @endif
    @endif
</x-filament-panels::page>
