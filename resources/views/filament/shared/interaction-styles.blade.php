{{-- GLOBAL interaction feedback for the app's hand-rolled controls, injected into the <head> of every
     Filament panel (admin / console / client). It replaces the old per-page, per-family enumeration
     ({@see filament/operate/partials/interactions.blade.php}) with one prefix-based sheet so EVERY
     custom control — any *-btn / *-tab / *-pill / *-loctab / *-select and any interactive *-chip —
     gets identical hover / press / focus / busy feedback, and a NEW button family is covered the
     moment it is named by convention.

     Two design rules keep it safe:
       1. Everything is wrapped in :where() so the sheet carries ZERO specificity — it fills gaps but
          never overrides a page's own intentional hover colors.
       2. Effects are color-agnostic (transform / shadow / brightness), so they read correctly over
          any surface palette and in both light and dark themes.
     Filament's native components (.fi-*) already animate and are explicitly excluded. --}}
<style data-lp-interactions>
    /* Pointer + smooth transition on every custom control (interactive chips only, never static labels). */
    :where([class*="-btn"], [class*="-tab"], [class*="-pill"], [class*="-loctab"], [class*="-select"],
           a[class*="-chip"], [class*="-chip"][wire\:click], [class*="-chip"][onclick]):not([class*="fi-"]) {
        transition: filter .12s ease, transform .06s ease, box-shadow .12s ease,
                    background-color .12s ease, border-color .12s ease, opacity .12s ease;
    }
    :where([class*="-btn"], [class*="-tab"], [class*="-pill"], [class*="-loctab"],
           a[class*="-chip"], [class*="-chip"][wire\:click], [class*="-chip"][onclick]):not([class*="fi-"]) {
        cursor: pointer;
    }

    /* Hover — buttons lift a touch with a soft shadow + faint brightness (works on any fill). */
    :where([class*="-btn"], [class*="-pill"], [class*="-loctab"]):not([class*="fi-"]):hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 7px rgba(15, 23, 42, .12);
        filter: brightness(1.03);
    }
    /* Ghost (non-solid) buttons also pick up a faint fill so transparent controls react too. */
    :where([class*="-btn"], [class*="-pill"]):not(.primary):not(.danger):not(.green):not(.on):not([class*="fi-"]):hover {
        background-color: rgba(148, 163, 184, .14);
    }
    /* Interactive chips / tabs / selects: brightness + a hairline shadow, without the lift. */
    :where(a[class*="-chip"], [class*="-chip"][wire\:click], [class*="-chip"][onclick],
           [class*="-tab"], [class*="-select"]):not([class*="fi-"]):not(.on):hover {
        filter: brightness(1.04);
        box-shadow: 0 1px 4px rgba(15, 23, 42, .10);
    }

    /* Press — a tactile depress the instant the click registers. */
    :where([class*="-btn"], [class*="-tab"], [class*="-pill"], [class*="-loctab"],
           a[class*="-chip"], [class*="-chip"][wire\:click], [class*="-chip"][onclick]):not([class*="fi-"]):active {
        transform: translateY(1px) scale(.985);
        box-shadow: none;
    }

    /* Keyboard focus ring (a11y) — visible in both themes. */
    :where([class*="-btn"], [class*="-tab"], [class*="-pill"], [class*="-loctab"], [class*="-select"],
           a[class*="-chip"], [class*="-chip"][wire\:click], [class*="-chip"][onclick]):not([class*="fi-"]):focus-visible {
        outline: 2px solid #6366f1;
        outline-offset: 1px;
    }

    /* Busy — disabled / wire:loading controls read as working, not dead. */
    :where([class*="-btn"], [class*="-pill"], [class*="-loctab"]):not([class*="fi-"]):is(:disabled, [disabled], [aria-disabled="true"]) {
        opacity: .5;
        cursor: progress;
        box-shadow: none;
        transform: none;
        filter: none;
    }

    /* Classless inline-styled buttons (e.g. the photo-swap control) still get pointer + press + focus. */
    :where(button:not([class]), a[role="button"]:not([class])) {
        cursor: pointer;
        transition: transform .06s ease, filter .12s ease;
    }
    :where(button:not([class]):hover, a[role="button"]:not([class]):hover) { filter: brightness(1.05); }
    :where(button:not([class]):active, a[role="button"]:not([class]):active) { transform: translateY(1px) scale(.985); }
    :where(button:not([class]):focus-visible, a[role="button"]:not([class]):focus-visible) { outline: 2px solid #6366f1; outline-offset: 1px; }
    :where(button:not([class]):disabled) { opacity: .5; cursor: progress; }

    /* Respect reduced-motion: keep the color/brightness cues, drop the movement. */
    @media (prefers-reduced-motion: reduce) {
        :where([class*="-btn"], [class*="-tab"], [class*="-pill"], [class*="-loctab"],
               [class*="-chip"], button, a):hover,
        :where([class*="-btn"], [class*="-tab"], [class*="-pill"], [class*="-loctab"],
               [class*="-chip"], button, a):active {
            transform: none;
        }
    }
</style>
