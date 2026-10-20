<x-filament-panels::page>
@php
    $index = $this->index;
    $r = $this->roadmap;
    $typeLabel = fn (?string $t): string => \App\Roadmap\OfficeRoadmap::typeLabel($t);
    $num = fn ($v): string => $v === null ? '—' : (is_float($v) ? number_format($v, 1) : number_format((int) $v));
@endphp

    @if ($this->siteId === null)
        <x-filament::section>Choose a working tenant first.</x-filament::section>
    @elseif ($index === [])
        <x-filament::section>No GBP-backed office yet. Import an office from Google on Setup → Locations first.</x-filament::section>
    @else
        <div style="display:flex;flex-direction:column;gap:1rem">
            <x-filament::section>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.75rem">
                    <div style="font-size:.85rem;color:var(--gray-500)">What the map-pack winners have that each office does not, with the numbers. One line per office; pick one for its roadmap.</div>
                    <x-filament::button size="sm" wire:click="refreshProfiles" wire:confirm="Pull the Google Business Profiles for every office and its pack competitors? One Places call per place.">Refresh profiles</x-filament::button>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                    <thead><tr style="text-align:left;color:var(--gray-500);font-size:.72rem;text-transform:uppercase"><th style="padding:.4rem">Office</th><th>In the pack</th><th>Actions</th><th>First action</th></tr></thead>
                    <tbody>
                        @foreach ($index as $row)
                            <tr style="border-top:1px solid var(--gray-100);{{ $row['id'] === $this->locationId ? 'background:var(--gray-50)' : '' }}">
                                <td style="padding:.4rem"><a href="#" wire:click.prevent="$set('locationId', '{{ $row['id'] }}')" style="font-weight:500;text-decoration:underline">{{ $row['name'] }}</a></td>
                                <td style="color:var(--gray-500)">{{ $row['scanned'] > 0 ? $row['in_pack'].' of '.$row['scanned'].' town results' : 'not scanned' }}</td>
                                <td><strong>{{ $row['actions'] }}</strong></td>
                                <td style="color:var(--gray-500)">{{ $row['first'] ?? 'Nothing to do from the pack data' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>

            @if ($r !== null)
                <x-filament::section>
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap">
                        <h2 style="font-size:1.1rem;font-weight:600">{{ $r['office']['name'] }} · {{ $r['towns'] }} towns</h2>
                        <div style="font-size:.75rem;color:var(--gray-400)">Profiles {{ $r['profiled_at'] ? 'refreshed '.$r['profiled_at']->diffForHumans() : 'not pulled yet' }}</div>
                    </div>

                    <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500);margin:1rem 0 .4rem">Actions</h3>
                    @forelse ($r['actions'] as $a)
                        <div style="display:flex;gap:.6rem;align-items:flex-start;padding:.45rem 0;border-top:1px solid var(--gray-100)">
                            <input type="checkbox" disabled style="margin-top:.25rem">
                            <div><div style="font-weight:500">{{ $a['title'] }}</div><div style="font-size:.8rem;color:var(--gray-500)">{{ $a['detail'] }}</div></div>
                        </div>
                    @empty
                        <p style="font-size:.85rem;color:var(--gray-500)">Nothing to do from the pack data: the listing matches or beats the pack winners on every measured point.</p>
                    @endforelse

                    <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500);margin:1.25rem 0 .4rem">Keywords</h3>
                    <table style="width:100%;border-collapse:collapse;font-size:.82rem">
                        <thead><tr style="text-align:left;color:var(--gray-500);font-size:.72rem;text-transform:uppercase"><th style="padding:.4rem">Keyword</th><th>Pack winners</th><th>Their Google type</th><th>In the pack</th><th>Absent</th><th>Off page 1</th></tr></thead>
                        <tbody>
                            @forelse ($r['keywords'] as $k)
                                <tr style="border-top:1px solid var(--gray-100);vertical-align:top">
                                    <td style="padding:.4rem;font-weight:500">{{ $k['query'] }}</td>
                                    <td>
                                        @forelse ($k['winners'] as $w)
                                            <div>{{ $w['name'] }} <span style="color:var(--gray-400)">· {{ $w['cells'] }} cells{{ $w['profile'] && $w['profile']->ratings_total !== null ? ' · '.$w['profile']->rating.' ★ ('.$w['profile']->ratings_total.')' : '' }}</span></div>
                                        @empty
                                            <span style="color:var(--gray-400)">no pack scan yet</span>
                                        @endforelse
                                    </td>
                                    <td style="color:{{ $k['type_match']['winners_type'] !== null && $k['type_match']['ours'] !== null && $k['type_match']['winners_type'] !== $k['type_match']['ours'] ? '#b45309' : 'var(--gray-500)' }}">{{ $typeLabel($k['type_match']['winners_type']) }}{{ $k['type_match']['ours'] !== null ? ' · you: '.$typeLabel($k['type_match']['ours']) : '' }}</td>
                                    <td>{{ $k['scanned'] > 0 ? $k['in_pack'].' of '.$k['scanned'] : '—' }}</td>
                                    <td>{{ $k['scanned'] > 0 ? $k['absent'] : '—' }}</td>
                                    <td>{{ $k['organic']['scanned'] > 0 ? $k['organic']['off_page1'].' of '.$k['organic']['scanned'] : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" style="padding:.6rem;color:var(--gray-500)">No priority keywords and no coverage plan for this office yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>

                    <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500);margin:1.25rem 0 .4rem">Your listing against the pack winners</h3>
                    @if ($r['listing']['winners_profiled'] === 0)
                        <p style="font-size:.85rem;color:var(--gray-500)">No winner profiled yet{{ $r['listing']['winners_total'] > 0 ? ' — press Refresh profiles' : ' — run a grid scan first' }}.</p>
                    @else
                        <table style="width:100%;max-width:560px;border-collapse:collapse;font-size:.85rem">
                            <thead><tr style="text-align:left;color:var(--gray-500);font-size:.72rem;text-transform:uppercase"><th style="padding:.4rem"></th><th>You</th><th>Winners' median</th><th>Best winner</th></tr></thead>
                            <tbody>
                                @foreach ($r['listing']['rows'] as $row)
                                    <tr style="border-top:1px solid var(--gray-100)"><td style="padding:.4rem;font-weight:500">{{ $row['label'] }}</td><td style="color:{{ $row['gap'] ? '#b45309' : 'inherit' }}">{{ $num($row['ours']) }}</td><td>{{ $num($row['median']) }}</td><td>{{ $num($row['best']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p style="font-size:.72rem;color:var(--gray-400);margin-top:.4rem">Across {{ $r['listing']['winners_profiled'] }} of {{ $r['listing']['winners_total'] }} pack winners with a profile. Google type{{ $r['office']['profile']?->primary_type ? ': '.$typeLabel($r['office']['profile']->primary_type) : ' unknown' }}.</p>
                    @endif

                    <h3 style="font-size:.8rem;text-transform:uppercase;color:var(--gray-500);margin:1.25rem 0 .4rem">Reach</h3>
                    @if ($r['proximity']['unknown'] > 0)
                        <p style="font-size:.85rem;color:var(--gray-500)">Not judged yet: the office or the winners have no coordinates on file.</p>
                    @elseif ($r['proximity']['out_of_reach'] === [])
                        <p style="font-size:.85rem;color:var(--gray-500)">No town is out of the pack's reach on distance: for every town, this office is at least as close as most winners.</p>
                    @else
                        <table style="width:100%;max-width:720px;border-collapse:collapse;font-size:.82rem">
                            <thead><tr style="text-align:left;color:var(--gray-500);font-size:.72rem;text-transform:uppercase"><th style="padding:.4rem">Town</th><th>Population</th><th>You</th><th>Nearest winner</th></tr></thead>
                            <tbody>
                                @foreach ($r['proximity']['out_of_reach'] as $t)
                                    <tr style="border-top:1px solid var(--gray-100)"><td style="padding:.4rem">{{ $t['label'] }}</td><td>{{ number_format($t['population']) }}</td><td>{{ $t['ours_miles'] }} mi</td><td>{{ $t['winner'] }} · {{ $t['winner_miles'] }} mi</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
