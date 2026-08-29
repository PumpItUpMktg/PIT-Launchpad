<x-filament-panels::page>
    @php($r = $this->report)

    @if ($r === null)
        <x-filament::section><p style="color:var(--gray-500)">No location selected.</p></x-filament::section>
    @else
        <x-filament::section>
            <h2 style="font-size:1.25rem;font-weight:600">Where your business is listed — {{ $r->locationName }}</h2>
            <p style="color:var(--gray-500);font-size:.9rem;margin:.5rem 0 1.25rem;max-width:60ch">
                Search engines trust a business more when its name, address, and phone number match everywhere they
                appear online. Here is where yours stands.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem">
                <div style="border:1px solid #bfe3cc;background:#ebf7ef;border-radius:.6rem;padding:.9rem 1rem">
                    <div style="font-size:.72rem;text-transform:uppercase;color:var(--gray-500)">Listed correctly</div>
                    <div style="font-size:1.6rem;font-weight:600;color:#15803d">{{ $r->listedCorrectly }}</div>
                </div>
                <div style="border:1px solid #fcd9a6;background:#fef6e7;border-radius:.6rem;padding:.9rem 1rem">
                    <div style="font-size:.72rem;text-transform:uppercase;color:var(--gray-500)">Wrong information</div>
                    <div style="font-size:1.6rem;font-weight:600;color:#b45309">{{ $r->wrongInformation }}</div>
                </div>
                <div style="border:1px solid #d6e2fb;background:#eff4ff;border-radius:.6rem;padding:.9rem 1rem">
                    <div style="font-size:.72rem;text-transform:uppercase;color:var(--gray-500)">Being added now</div>
                    <div style="font-size:1.6rem;font-weight:600;color:#1d4ed8">{{ $r->beingAdded }}</div>
                </div>
                <div style="border:1px solid var(--gray-200);border-radius:.6rem;padding:.9rem 1rem">
                    <div style="font-size:.72rem;text-transform:uppercase;color:var(--gray-500)">Still available</div>
                    <div style="font-size:1.6rem;font-weight:600">{{ $r->stillAvailable }}</div>
                </div>
            </div>
        </x-filament::section>

        @if (! empty($r->corrections))
            <x-filament::section>
                <h3 style="font-weight:600;margin-bottom:.25rem">What we're fixing</h3>
                <p style="color:var(--gray-500);font-size:.85rem;margin-bottom:1rem">These listings show details that don't match your business — we're correcting them.</p>
                @foreach ($r->corrections as $c)
                    <div style="margin-bottom:1rem">
                        <div style="font-size:.72rem;text-transform:uppercase;color:var(--gray-500);margin-bottom:.4rem">{{ $c['directory'] }}</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--gray-200);border-radius:.5rem;overflow:hidden">
                            <div style="padding:.75rem 1rem;background:var(--gray-50);border-right:1px solid var(--gray-200)">
                                <div style="font-size:.7rem;text-transform:uppercase;color:var(--gray-500);margin-bottom:.4rem">Currently shows</div>
                                @foreach ($c['fields'] as $f)
                                    <div style="font-size:.85rem;margin-bottom:.25rem"><span style="color:var(--gray-500)">{{ $f['field'] }}:</span> <span style="color:#b91c1c">{{ $f['found'] }}</span></div>
                                @endforeach
                            </div>
                            <div style="padding:.75rem 1rem">
                                <div style="font-size:.7rem;text-transform:uppercase;color:var(--gray-500);margin-bottom:.4rem">Should show</div>
                                @foreach ($c['fields'] as $f)
                                    <div style="font-size:.85rem;margin-bottom:.25rem"><span style="color:var(--gray-500)">{{ $f['field'] }}:</span> <span style="color:#15803d">{{ $f['expected'] }}</span></div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </x-filament::section>
        @endif

        @if (! empty($r->available))
            <x-filament::section>
                <h3 style="font-weight:600;margin-bottom:.5rem">Directories we can still add you to</h3>
                <div style="display:flex;flex-wrap:wrap;gap:.4rem">
                    @foreach ($r->available as $name)
                        <span style="font-size:.8rem;background:var(--gray-100);border-radius:99px;padding:.25rem .7rem">{{ $name }}</span>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
