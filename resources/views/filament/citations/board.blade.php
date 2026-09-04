<x-filament-panels::page>
    @php($cards = $this->board)

    <div style="display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:4px">
        <x-filament::button wire:click="scanAll" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled">
            Scan all listings
        </x-filament::button>
    </div>

    @if (empty($cards))
        <x-filament::section>
            <p style="color:var(--gray-500)">No locations for this tenant yet.</p>
        </x-filament::section>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem">
            @foreach ($cards as $card)
                @if (! $card->hasGbp)
                    {{-- No GBP attached — citations need a verified listing to scan against. --}}
                    <x-filament::section>
                        <div style="text-align:center;padding:1rem 0">
                            <h3 style="font-weight:600">{{ $card->name }}</h3>
                            <p style="color:var(--gray-500);font-size:.8rem;margin:.5rem 0 1rem">No GBP listing attached. Citations need a verified listing to scan against.</p>
                            <x-filament::button size="sm" color="gray" tag="a"
                                href="{{ \App\Filament\Resources\LocationResource::getUrl('edit', ['record' => $card->locationId]) }}">
                                Attach GBP listing
                            </x-filament::button>
                        </div>
                    </x-filament::section>
                @else
                    <x-filament::section>
                        <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
                            <div>
                                <h3 style="font-weight:600">{{ $card->name }}</h3>
                                <p style="color:var(--gray-500);font-size:.8rem">{{ $card->typeLabel }}{{ $card->hasNap ? '' : ' · no NAP profile' }}</p>
                            </div>
                            @if ($card->isScanning())
                                <x-filament::badge color="info">Scanning…</x-filament::badge>
                            @elseif ($card->neverScanned())
                                <x-filament::badge color="gray">Never scanned</x-filament::badge>
                            @else
                                <x-filament::badge color="success">{{ $card->coveragePercent }}% live</x-filament::badge>
                            @endif
                        </div>

                        @if (! $card->neverScanned() && $card->eligible > 0)
                            @php($seg = fn ($n) => $card->eligible > 0 ? (100 * $n / $card->eligible) : 0)
                            <div style="margin-top:.75rem">
                                <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.35rem">
                                    <span style="color:var(--gray-500)">Coverage</span><strong>{{ $card->coveragePercent }}%</strong>
                                </div>
                                <div style="height:7px;border-radius:99px;background:var(--gray-200);overflow:hidden;display:flex">
                                    <i style="display:block;height:100%;width:{{ $seg($card->live) }}%;background:#16a34a"></i>
                                    <i style="display:block;height:100%;width:{{ $seg($card->mismatch) }}%;background:#f0a93b"></i>
                                    <i style="display:block;height:100%;width:{{ $seg($card->submitted) }}%;background:#2563eb"></i>
                                </div>
                                <div style="display:flex;gap:.9rem;flex-wrap:wrap;font-size:.72rem;color:var(--gray-500);margin-top:.5rem">
                                    <span>● Live {{ $card->live }}</span>
                                    <span style="color:#b45309">● Mismatch {{ $card->mismatch }}</span>
                                    <span style="color:#2563eb">● Submitted {{ $card->submitted }}</span>
                                    <span>● Missing {{ $card->missing }}</span>
                                </div>
                            </div>
                        @endif

                        @if (! empty($card->nap))
                            <p style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--gray-200);font-size:.78rem;color:var(--gray-500);line-height:1.5">
                                <strong>NAP</strong><br>
                                {{ $card->nap['business_name'] ?? '' }} ·
                                {{ trim(($card->nap['address_1'] ?? '').' '.($card->nap['city'] ?? '').' '.($card->nap['state'] ?? '').' '.($card->nap['postal'] ?? '')) }} ·
                                {{ $card->nap['phone_primary'] ?? '' }}
                            </p>
                        @endif

                        <div style="margin-top:.9rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                            <a href="{{ \App\Filament\Pages\Citations\CitationsWorkspace::getUrl(['location' => $card->locationId]) }}"
                               style="font-size:.82rem;color:var(--primary-600)">Open workspace →</a>
                            <x-filament::button size="sm" wire:click="launchScan('{{ $card->locationId }}')" wire:loading.attr="disabled">
                                {{ $card->neverScanned() ? 'Launch first scan' : ($card->isScanning() ? 'Scanning…' : 'Rescan') }}
                            </x-filament::button>
                        </div>
                    </x-filament::section>
                @endif
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
