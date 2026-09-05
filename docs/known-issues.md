# Known issues

Living list of confirmed, non-blocking issues — so a red CI or odd behavior isn't mistaken
for new breakage (and the reflex doesn't become "probably the flake").

## Flaky tests

### `tests/Feature/Publishing/RenderVariantsTest.php` — order-dependent intermittent

- **Symptom:** `it stores downscale variants beside the source render and records their R2 keys`
  fails in a full-suite run (`Failed asserting that false is true`), but **passes in isolation**
  (`pest tests/Feature/Publishing/RenderVariantsTest.php` → green).
- **Nature:** order-dependent flake in the image-render suite (a fake/shared-state or random
  bleed from an earlier test), not a regression — reproduced on a full run whose changes were
  confined to `app/Locations/**` (nothing touching rendering).
- **Impact:** CI can go red for a reason unrelated to the diff. Re-run the single file to confirm
  it's this flake before spending time on it.
- **Fix:** isolate the shared state / fake (likely a Storage or fal fake leaking across tests) so
  the test is order-independent. Not yet scheduled.
