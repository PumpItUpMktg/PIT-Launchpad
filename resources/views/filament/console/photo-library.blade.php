<x-filament-panels::page>
    @include('filament.console.partials.site-switcher')

    @php $photos = $this->photos; @endphp

    <style>
        .pl-wrap { display:flex; flex-direction:column; gap:16px; }
        .pl-bar { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:14px; }
        .pl-field label { display:block; font-size:11px; color:#94a3b8; margin-bottom:4px; }
        .pl-field input[type=text] { border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:9px 11px; font-size:13px; background:transparent; color:inherit; min-width:200px; }
        .pl-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:14px; }
        .pl-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; overflow:hidden; display:flex; flex-direction:column; }
        .pl-card img { width:100%; aspect-ratio:4/3; object-fit:cover; background:rgba(148,163,184,.15); }
        .pl-meta { padding:10px; display:flex; flex-direction:column; gap:6px; font-size:12px; }
        .pl-tags { display:flex; flex-wrap:wrap; gap:4px; }
        .pl-tag { font-size:10.5px; background:rgba(148,163,184,.2); border-radius:999px; padding:2px 8px; }
        .pl-actions { display:flex; gap:8px; margin-top:2px; }
        .pl-actions button { font-size:11.5px; color:var(--primary-500,#6366f1); background:none; border:none; cursor:pointer; padding:0; }
        .pl-actions button.danger { color:#ef4444; }
        .pl-empty { color:#94a3b8; font-size:13px; padding:24px; text-align:center; border:1px dashed rgba(148,163,184,.4); border-radius:12px; }
        .pl-edit input { display:block; width:100%; margin-bottom:6px; border:1px solid rgba(148,163,184,.4); border-radius:6px; padding:6px 8px; font-size:12px; background:transparent; color:inherit; }
    </style>

    <div class="pl-wrap">
        <p style="color:#94a3b8;font-size:13px;margin:0">
            Your reusable stock of job photos. Upload once, then attach any to a job from Job Review — each job
            gets its own copy geotagged to that job's location. Tag a photo to find a similar one later.
        </p>

        {{-- Upload --}}
        <div class="pl-bar">
            <div class="pl-field" style="flex:1;min-width:240px">
                <label>Add photos (JPEG/PNG — you can select many)</label>
                <input type="file" wire:model="uploads" multiple accept="image/*">
            </div>
            <div class="pl-field">
                <x-filament::button wire:click="upload" wire:loading.attr="disabled" icon="heroicon-o-arrow-up-tray">
                    Upload to library
                </x-filament::button>
            </div>
            <div class="pl-field" style="margin-left:auto">
                <label>Filter by tag</label>
                <input type="text" wire:model.live.debounce.400ms="filterTag" placeholder="e.g. kitchen">
            </div>
        </div>

        {{-- Grid --}}
        @if ($photos === [])
            <div class="pl-empty">No photos yet — upload some above.</div>
        @else
            <div class="pl-grid">
                @foreach ($photos as $photo)
                    <div class="pl-card" wire:key="lib-{{ $photo['id'] }}">
                        <img src="{{ $photo['url'] }}" alt="{{ $photo['label'] ?? $photo['filename'] }}" loading="lazy">
                        <div class="pl-meta">
                            @if ($editingId === $photo['id'])
                                <div class="pl-edit">
                                    <input type="text" wire:model="editLabel" placeholder="Label">
                                    <input type="text" wire:model="editTags" placeholder="tags, comma separated">
                                    <div class="pl-actions">
                                        <button wire:click="saveEdit">Save</button>
                                        <button wire:click="cancelEdit">Cancel</button>
                                    </div>
                                </div>
                            @else
                                <strong>{{ $photo['label'] ?: ($photo['filename'] ?: 'Untitled') }}</strong>
                                @if ($photo['tags'] !== [])
                                    <div class="pl-tags">
                                        @foreach ($photo['tags'] as $tag)
                                            <span class="pl-tag">{{ $tag }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="pl-actions">
                                    <button wire:click="startEdit('{{ $photo['id'] }}')">Tag / label</button>
                                    <button class="danger" wire:click="delete('{{ $photo['id'] }}')"
                                            wire:confirm="Remove this photo from the library? Jobs already using a copy keep theirs.">Delete</button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
