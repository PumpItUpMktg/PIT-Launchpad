{{-- Shared interaction feedback for the Operate boards' custom buttons + chips: hover lift, tactile
     press, a keyboard focus ring, and a busy state for in-flight / disabled controls. Defined ONCE
     here and @included by every Operate surface so every button feels identical. Each board names its
     buttons differently (.ob-btn / .hm-btn / .il-btn / .g-btn / .orph-btn / .lv-btn / .pl-btn /
     .rdy-btn), so the selector lists enumerate them; color-agnostic effects (transform / box-shadow /
     filter) keep it safe across each surface's own palette. --}}
<style>
    .ob-btn,.ob-tab,.hm-btn,.il-btn,.g-btn,.orph-btn,.lv-btn,.pl-btn,.rdy-btn,.op-chip {
        transition:filter .12s ease, transform .06s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease;
    }
    /* Hover — every control lifts a touch. */
    .ob-btn:hover,.hm-btn:hover,.il-btn:hover,.g-btn:hover,.orph-btn:hover,.lv-btn:hover,.pl-btn:hover,.rdy-btn:hover,.op-chip:hover {
        transform:translateY(-1px); box-shadow:0 2px 7px rgba(15,23,42,.12);
    }
    /* Ghost (non-solid) buttons also pick up a subtle fill. */
    .ob-btn:not(.primary):not(.danger):hover,.hm-btn:not(.primary):not(.danger):hover,.il-btn:not(.primary):not(.danger):hover,.g-btn:not(.primary):not(.danger):hover,.orph-btn:not(.primary):not(.danger):hover,.lv-btn:not(.primary):not(.danger):hover,.pl-btn:not(.primary):not(.danger):hover,.rdy-btn:not(.primary):not(.danger):hover {
        background:rgba(148,163,184,.14);
    }
    /* Solid primary buttons darken slightly instead. */
    .ob-btn.primary:hover,.hm-btn.primary:hover,.il-btn.primary:hover,.g-btn.primary:hover,.orph-btn.primary:hover,.lv-btn.primary:hover,.pl-btn.primary:hover,.rdy-btn.primary:hover {
        filter:brightness(.93);
    }
    /* Danger buttons take a red tint. */
    .ob-btn.danger:hover,.hm-btn.danger:hover,.il-btn.danger:hover,.g-btn.danger:hover,.orph-btn.danger:hover,.lv-btn.danger:hover,.pl-btn.danger:hover,.rdy-btn.danger:hover {
        background:rgba(220,38,38,.10); border-color:rgba(220,38,38,.5);
    }
    /* Press — a tactile depress the instant the click registers. */
    .ob-btn:active,.ob-tab:active,.hm-btn:active,.il-btn:active,.g-btn:active,.orph-btn:active,.lv-btn:active,.pl-btn:active,.rdy-btn:active,.op-chip:active {
        transform:translateY(1px) scale(.985); box-shadow:none;
    }
    /* Keyboard focus ring (a11y). */
    .ob-btn:focus-visible,.ob-tab:focus-visible,.hm-btn:focus-visible,.il-btn:focus-visible,.g-btn:focus-visible,.orph-btn:focus-visible,.lv-btn:focus-visible,.pl-btn:focus-visible,.rdy-btn:focus-visible,.op-chip:focus-visible {
        outline:2px solid #6366f1; outline-offset:1px;
    }
    /* Busy — disabled / wire:loading controls read as working, not dead. */
    .ob-btn:disabled,.ob-btn[disabled],.hm-btn:disabled,.hm-btn[disabled],.il-btn:disabled,.il-btn[disabled],.g-btn:disabled,.g-btn[disabled],.orph-btn:disabled,.orph-btn[disabled],.lv-btn:disabled,.lv-btn[disabled],.pl-btn:disabled,.pl-btn[disabled],.rdy-btn:disabled,.rdy-btn[disabled] {
        opacity:.5; cursor:progress; box-shadow:none; transform:none; filter:none;
    }
    /* Blog tabs: the inactive tab fills on hover; the active one stays put. */
    .ob-tab:hover:not(.on) { background:rgba(148,163,184,.16); color:#334155; }
</style>
