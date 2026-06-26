@php
    $preview = $this->preview;
    $rail = $this->rail;
    $brand = $preview['brand'] ?? [];
    $primary = $brand['primary'] ?? '#0B2545';
    $accent = $brand['accent'] ?? '#5BC0EB';
@endphp

<x-filament-panels::page>
<div class="pe-scope" style="--pe-primary: {{ $primary }}; --pe-accent: {{ $accent }};">
    <style>
        .pe-scope{--pe-line:#DDE4E9;--pe-ink:#172A2F;--pe-soft:#54666C}
        .pe-top{display:flex;align-items:center;gap:14px;margin-bottom:16px}
        .pe-back{font-size:13px;color:var(--pe-soft);text-decoration:none}
        .pe-spacer{margin-left:auto}
        .pe-actbar{display:flex;gap:10px}
        .pe-btn{font-family:'Inter',sans-serif;font-weight:600;font-size:13.5px;padding:9px 18px;border-radius:8px;border:none;cursor:pointer;background:var(--pe-primary);color:#fff;text-decoration:none;display:inline-block}
        .pe-btn.ghost{background:#fff;color:var(--pe-ink);border:1px solid var(--pe-line)}
        .pe-btn.sm{padding:5px 11px;font-size:12px}
        .pe-btn:disabled{opacity:.45;cursor:not-allowed}
        .pe-grid{display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start}
        .pe-doc{background:#fff;border:1px solid var(--pe-line);border-radius:14px;overflow:hidden}
        .pe-dochead{padding:18px 24px;border-bottom:1px solid var(--pe-line);background:linear-gradient(180deg,color-mix(in srgb,var(--pe-primary) 7%,#fff),#fff)}
        .pe-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--pe-primary)}
        .pe-perma{font-family:'Spline Sans Mono',monospace;font-size:12px;color:var(--pe-soft);margin-top:3px}
        .pe-sec{padding:16px 24px;border-bottom:1px solid #EEF2F4}
        .pe-sec:last-child{border-bottom:none}
        .pe-role{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--pe-soft);display:flex;align-items:center;gap:8px;margin-bottom:6px}
        .pe-role .ed{margin-left:auto}
        .pe-val{font-size:14.5px;line-height:1.55;color:var(--pe-ink)}
        .pe-val.empty{color:#9AA8AE;font-style:italic}
        .pe-val ul{margin:0;padding-left:1.1rem}
        .pe-img{display:flex;align-items:center;gap:9px;font-size:12.5px;color:var(--pe-soft);background:#F4F7F8;border:1px dashed var(--pe-line);border-radius:8px;padding:12px 14px}
        .pe-edit textarea{width:100%;border:1px solid var(--pe-primary);border-radius:8px;padding:10px;font:inherit;font-size:14px;min-height:80px}
        .pe-reasons{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
        .pe-reasons label{font-size:12px;border:1px solid var(--pe-line);border-radius:7px;padding:6px 11px;cursor:pointer;display:flex;align-items:center;gap:6px}
        .pe-reasons label:has(input:checked){border-color:var(--pe-primary);background:color-mix(in srgb,var(--pe-primary) 8%,#fff);font-weight:600}
        .pe-editfoot{display:flex;gap:8px}
        .pe-seo{padding:16px 24px;background:#FAFCFC;border-top:1px solid var(--pe-line)}
        .pe-seo .lbl{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--pe-soft)}
        .pe-seo .t{font-size:15px;color:#1A0DAB;margin-top:4px}
        .pe-seo .d{font-size:13px;color:var(--pe-soft);margin-top:2px}
        .pe-rail{position:sticky;top:16px;display:flex;flex-direction:column;gap:12px}
        .pe-card{background:#fff;border:1px solid var(--pe-line);border-radius:12px;padding:14px 16px}
        .pe-card h4{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--pe-soft);margin-bottom:8px}
        .pe-kv{font-size:13px;color:var(--pe-ink);line-height:1.5}
        .pe-kv .muted{color:var(--pe-soft)}
        .pe-note{font-size:11.5px;color:var(--pe-soft);font-style:italic;margin-top:8px}
        .pe-warn{font-size:11.5px;color:#A4262C;background:#FBE5E6;border-radius:6px;padding:6px 8px;margin-top:8px;line-height:1.4}
    </style>

    <div class="pe-top">
        <a href="{{ $this->backUrl() }}" class="pe-back">← Back to pages</a>
        <div class="pe-spacer"></div>
        <div class="pe-actbar">
            @if ($this->canPublish())
                <button class="pe-btn" wire:click="publish" wire:loading.attr="disabled">Publish</button>
            @elseif ($this->isApprovable())
                <button class="pe-btn" wire:click="approve" wire:loading.attr="disabled">Approve</button>
            @else
                <button class="pe-btn" disabled>{{ $this->record()?->buildStateLabel() ?? '—' }}</button>
            @endif
        </div>
    </div>

    <div class="pe-grid">
        {{-- The page as a visitor reads it: the kit's blocks in order, real copy, brand kit --}}
        <div class="pe-doc">
            <div class="pe-dochead">
                <div class="pe-eyebrow">{{ $brand['name'] ?? '' }}</div>
                <div class="pe-perma">{{ $preview['permalink'] }}</div>
            </div>

            @forelse ($preview['sections'] as $sec)
                <div class="pe-sec" wire:key="sec-{{ $sec['key'] }}">
                    @php $editable = $sec['editable'] && ! $sec['is_image'] && ! is_array($sec['value']); @endphp
                    <div class="pe-role">
                        <span>{{ str_replace('_', ' ', $sec['role']) }}</span>
                        @if ($editable && $editingKey !== $sec['key'])
                            <button class="pe-btn ghost sm ed" wire:click="startEdit('{{ $sec['key'] }}')">Edit</button>
                        @endif
                    </div>

                    @if ($editingKey === $sec['key'])
                        <div class="pe-edit">
                            <textarea wire:model="editValue"></textarea>
                            <div class="pe-reasons">
                                <label><input type="radio" wire:model="editReason" value="off_base"> Off-base (wrong facts)</label>
                                <label><input type="radio" wire:model="editReason" value="off_brand"> Off-brand (wrong voice)</label>
                                <label><input type="radio" wire:model="editReason" value="preference"> Just polishing</label>
                            </div>
                            <div class="pe-editfoot">
                                <button class="pe-btn sm" wire:click="saveEdit">Save</button>
                                <button class="pe-btn ghost sm" wire:click="cancelEdit">Cancel</button>
                            </div>
                        </div>
                    @elseif ($sec['is_image'])
                        <div class="pe-img">🖼 Image slot — renders on publish</div>
                    @elseif (is_array($sec['value']))
                        <div class="pe-val"><ul>@foreach ($sec['value'] as $item)<li>{{ is_array($item) ? implode(' — ', array_map('strval', $item)) : (string) $item }}</li>@endforeach</ul></div>
                    @elseif ($sec['empty'])
                        <div class="pe-val empty">empty</div>
                    @else
                        <div class="pe-val">{{ $sec['value'] }}</div>
                    @endif
                </div>
            @empty
                <div class="pe-sec"><div class="pe-val empty">No kit sections — this page hasn't been generated yet.</div></div>
            @endforelse

            <div class="pe-seo">
                <div class="lbl">Search appearance</div>
                <div class="t">{{ $preview['seo']['title'] ?? '—' }}</div>
                <div class="d">{{ $preview['seo']['meta_description'] ?? '—' }}</div>
            </div>
        </div>

        {{-- Strategy rail: why this page exists + what it targets (read-only; targeting lives in Structure) --}}
        <aside class="pe-rail">
            <div class="pe-card">
                <h4>Placement</h4>
                <div class="pe-kv">{{ $rail['placement']['label'] ?? '—' }}</div>
                @if (!empty($rail['placement']['subject']))
                    <div class="pe-kv muted">Subject: {{ $rail['placement']['subject'] }}</div>
                @endif
                @if (!empty($rail['placement']['mismatch']))
                    <div class="pe-warn">⚠ {{ $rail['placement']['mismatch_note'] }}</div>
                @endif
            </div>
            <div class="pe-card">
                <h4>Target</h4>
                @if (($rail['target']['has_target'] ?? false))
                    <div class="pe-kv"><b>{{ $rail['target']['primary'] }}</b><br>
                        <span class="muted">vol {{ $rail['target']['volume'] ?? '—' }} · diff {{ $rail['target']['difficulty'] ?? '—' }}</span>
                    </div>
                @else
                    <div class="pe-kv muted">{{ $rail['target']['note'] ?? 'No target set.' }}</div>
                @endif
            </div>
            <div class="pe-card">
                <h4>Performance</h4>
                <div class="pe-kv muted">{{ $rail['performance']['note'] ?? 'Tracked after publish.' }}</div>
            </div>
            <div class="pe-note">{{ $rail['locked_note'] ?? '' }}</div>
        </aside>
    </div>
</div>
</x-filament-panels::page>
