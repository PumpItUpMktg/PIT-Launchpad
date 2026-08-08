{{-- Swap-photo control for a post card. Expects $id (content id). Gated on EDIT capability; uses the
     host page's SwapsPostImage trait (startSwap/cancelSwap/photoUpload). --}}
@if (! empty($id) && $this->can(\App\Security\Capability::EditContent))
    <div style="display:flex; gap:8px; align-items:center; margin-top:4px; flex-wrap:wrap;">
        @if ($swapFor === $id)
            <label style="font-size:12px; font-weight:600; padding:6px 12px; border-radius:8px; border:1px dashed #4f46e5; color:#4f46e5; cursor:pointer;">
                <input type="file" wire:model="photoUpload" accept="image/*" style="display:none;">
                <span wire:loading.remove wire:target="photoUpload">Choose photo…</span>
                <span wire:loading wire:target="photoUpload">Uploading…</span>
            </label>
            <button type="button" wire:click="cancelSwap"
                    style="font-size:12px; padding:6px 10px; border-radius:8px; border:1px solid rgba(148,163,184,.4); background:transparent; color:#64748b; cursor:pointer;">Cancel</button>
        @else
            <button type="button" wire:click="startSwap('{{ $id }}')"
                    style="font-size:12px; font-weight:600; padding:6px 12px; border-radius:8px; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; cursor:pointer;">📷 Upload photo</button>
        @endif
    </div>
@endif
