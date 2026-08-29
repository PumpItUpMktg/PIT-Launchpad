<x-filament-panels::page>
    @php($ws = $this->workspace)
    @php($stats = $ws['stats'])
    @php($detail = $this->activeDetail)

    @if ($this->getLocation() === null)
        <x-filament::section><p style="color:var(--gray-500)">No location selected.</p></x-filament::section>
    @else
        <div style="display:flex;justify-content:flex-end;margin-bottom:.5rem">
            <a href="{{ \App\Filament\Pages\Citations\CitationsReport::getUrl(['location' => $this->getLocation()->id]) }}"
               style="color:var(--primary-600);font-size:.85rem">View client report →</a>
        </div>
        {{-- Stat strip --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem">
            <x-filament::section><div style="font-size:.72rem;text-transform:uppercase;color:var(--gray-500)">Live &amp; correct</div><div style="font-size:1.5rem;font-weight:600;color:#16a34a">{{ $stats['live'] ?? 0 }}</div></x-filament::section>
            <x-filament::section><div style="font-size:.72rem;text-transform:uppercase;color:var(--gray-500)">Wrong NAP</div><div style="font-size:1.5rem;font-weight:600;color:#b45309">{{ $stats['mismatch'] ?? 0 }}</div></x-filament::section>
            <x-filament::section><div style="font-size:.72rem;text-transform:uppercase;color:var(--gray-500)">In flight</div><div style="font-size:1.5rem;font-weight:600">{{ $stats['in_flight'] ?? 0 }}</div></x-filament::section>
            <x-filament::section><div style="font-size:.72rem;text-transform:uppercase;color:var(--gray-500)">Missing</div><div style="font-size:1.5rem;font-weight:600">{{ $stats['missing'] ?? 0 }}</div><div style="font-size:.72rem;color:var(--gray-400)">{{ $stats['submittable_missing'] ?? 0 }} submittable</div></x-filament::section>
        </div>

        {{-- Filters --}}
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:1rem">
            @foreach (['all' => 'All', 'needs_action' => 'Needs action', 'mismatch' => 'Mismatch', 'missing' => 'Missing', 'in_flight' => 'In flight', 'local' => 'Local'] as $key => $label)
                <x-filament::button size="xs" wire:click="$set('filter', '{{ $key }}')" :color="$this->filter === $key ? 'primary' : 'gray'">{{ $label }}</x-filament::button>
            @endforeach
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Filter directories…"
                   style="flex:1;min-width:160px;border:1px solid var(--gray-300);border-radius:.5rem;padding:.4rem .6rem">
            <label style="font-size:.8rem;color:var(--gray-500);display:flex;gap:.35rem;align-items:center">
                <input type="checkbox" wire:model.live="showNotRelevant"> Show not-relevant
            </label>
        </div>

        <div x-data="{ selected: [] }" style="margin-top:1rem">
            <div x-show="selected.length" x-cloak
                 style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.6rem .9rem;background:var(--primary-50);border:1px solid var(--primary-200);border-radius:.5rem;margin-bottom:.5rem">
                <span><strong x-text="selected.length"></strong> selected</span>
                <span style="display:flex;gap:.5rem">
                    <x-filament::button size="xs" color="gray" x-on:click="selected = []">Clear</x-filament::button>
                    <x-filament::button size="xs" x-on:click="$wire.createWorkOrders(selected); selected = []">Create work orders</x-filament::button>
                </span>
            </div>

            <x-filament::section>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                    <thead>
                        <tr style="text-align:left;color:var(--gray-500);font-size:.72rem;text-transform:uppercase">
                            <th style="padding:.5rem"></th><th>Directory</th><th>Tier</th><th>Status</th><th>NAP match</th><th>Last checked</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ws['rows'] as $row)
                            <tr style="border-top:1px solid var(--gray-100)">
                                <td style="padding:.5rem">
                                    @if ($row->submittable && $row->statusId)
                                        <input type="checkbox" value="{{ $row->statusId }}" x-model="selected">
                                    @endif
                                </td>
                                <td>
                                    <div style="font-weight:500">{{ $row->directoryName }}</div>
                                    @if ($row->listingUrl)<div style="font-size:.72rem;color:var(--gray-400)">{{ $row->listingUrl }}</div>@endif
                                </td>
                                <td style="color:var(--gray-500)">{{ ucfirst($row->tierLabel) }}{{ $row->isLocal ? ' · Local' : '' }}</td>
                                <td><x-filament::badge :color="$row->chip['color']">{{ $row->chip['label'] }}</x-filament::badge></td>
                                <td style="color:{{ $row->chip['key'] === 'mismatch' ? '#b45309' : 'var(--gray-500)' }}">{{ $row->napMatchSummary }}</td>
                                <td style="color:var(--gray-400);font-size:.75rem">{{ $row->lastCheckedAt?->diffForHumans() ?? '—' }}</td>
                                <td style="text-align:right"><x-filament::button size="xs" color="gray" wire:click="openRow('{{ $row->directoryId }}')">Details</x-filament::button></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" style="padding:1rem;color:var(--gray-500)">No directories match this filter.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        {{-- Right drawer --}}
        @if ($detail !== null)
            <div wire:click="closeRow" style="position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:40"></div>
            <aside style="position:fixed;top:0;right:0;bottom:0;width:min(520px,100%);background:var(--gray-50);z-index:41;overflow:auto;box-shadow:-8px 0 24px rgba(0,0,0,.1)">
                <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;min-height:100%">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start">
                        <h2 style="font-size:1.1rem;font-weight:600">Listing detail</h2>
                        <button wire:click="closeRow" style="border:0;background:none;font-size:1.4rem;cursor:pointer;color:var(--gray-500)">&times;</button>
                    </div>

                    @if ($detail['status'])
                        @php($mm = $detail['status']->mismatch_fields ?? [])
                        @if (! empty($mm))
                            <div>
                                <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500);margin-bottom:.4rem">What to correct</h3>
                                @foreach ($mm as $field => $vals)
                                    <div style="font-size:.85rem;margin-bottom:.3rem">
                                        <span style="color:var(--gray-500)">{{ ucfirst($field) }}:</span>
                                        <span style="color:#b91c1c">{{ $vals['found'] ?? '' }}</span> →
                                        <span style="color:#16a34a">{{ $vals['expected'] ?? '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div>
                            <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500);margin-bottom:.4rem">History</h3>
                            @forelse ($detail['events'] as $ev)
                                <div style="font-size:.82rem;padding:.3rem 0;border-left:2px solid var(--gray-200);padding-left:.6rem;margin-left:.2rem">
                                    <strong>{{ ucfirst($ev->event_type->value) }}</strong>
                                    <span style="color:var(--gray-500)">· {{ $ev->occurred_at?->format('M j, Y') }}</span>
                                </div>
                            @empty
                                <p style="font-size:.8rem;color:var(--gray-400)">No recorded history yet.</p>
                            @endforelse
                        </div>
                    @else
                        <p style="color:var(--gray-500);font-size:.85rem">This directory has not been scanned yet.</p>
                    @endif

                    <div style="margin-top:auto;display:flex;gap:.5rem;flex-wrap:wrap;padding-top:1rem;border-top:1px solid var(--gray-200)">
                        @if ($detail['status'])
                            <x-filament::button size="sm" wire:click="createWorkOrders(['{{ $detail['status']->id }}'])">Create fix work order</x-filament::button>
                        @endif
                        <x-filament::button size="sm" color="gray" wire:click="markNotRelevant('{{ $this->activeDirectoryId }}')">Mark not relevant</x-filament::button>
                    </div>
                </div>
            </aside>
        @endif
    @endif
</x-filament-panels::page>
