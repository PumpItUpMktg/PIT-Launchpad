<x-filament-panels::page>
    @php($rows = $this->rows)

    <p style="color:var(--gray-500);font-size:.9rem;margin-bottom:.5rem">
        Every tenant's citation health — median coverage across each tenant's listings, and the exceptions that
        need attention. Most-urgent first.
    </p>

    <x-filament::section>
        <table style="width:100%;border-collapse:collapse;font-size:.88rem">
            <thead>
                <tr style="text-align:left;color:var(--gray-500);font-size:.72rem;text-transform:uppercase">
                    <th style="padding:.5rem">Tenant</th><th>Listings</th><th>Median coverage</th>
                    <th>Mismatch</th><th>Submitted</th><th>Stalled</th><th>Last scan</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr style="border-top:1px solid var(--gray-100)">
                        <td style="padding:.55rem">
                            <a href="{{ \App\Filament\Pages\Citations\CitationsBoard::getUrl(['site' => $row->siteId]) }}"
                               style="font-weight:500;color:var(--primary-600)">{{ $row->tenantName }}</a>
                        </td>
                        <td>{{ $row->listingCount }}</td>
                        <td><strong>{{ $row->medianCoverage === null ? '—' : $row->medianCoverage.'%' }}</strong></td>
                        <td>@if ($row->mismatchCount)<x-filament::badge color="warning">{{ $row->mismatchCount }}</x-filament::badge>@else — @endif</td>
                        <td>@if ($row->submittedCount)<x-filament::badge color="info">{{ $row->submittedCount }}</x-filament::badge>@else — @endif</td>
                        <td>@if ($row->stalledCount)<x-filament::badge color="danger">{{ $row->stalledCount }}</x-filament::badge>@else — @endif</td>
                        <td style="color:var(--gray-400);font-size:.8rem">{{ $row->lastScanAt?->diffForHumans() ?? 'never' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:1rem;color:var(--gray-500)">No tenants with locations yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
