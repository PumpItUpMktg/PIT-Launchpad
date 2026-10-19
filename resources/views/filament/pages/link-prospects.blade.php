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
                                <td style="text-align:right;white-space:nowrap"><a href="https://{{ $p->domain }}" target="_blank" rel="noopener" style="font-size:.75rem;color:#2563eb;text-decoration:underline">Open ↗</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" style="padding:1rem;color:var(--gray-500)">Nothing in this view.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
