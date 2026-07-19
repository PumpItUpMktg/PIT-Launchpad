{{-- lp- styles for the admin surfaces (Overview, per-site Cockpit). Scoped under .lpa so they
     sit INSIDE the normal Filament chrome (unlike the guided full-bleed .lp-scope). No theme build. --}}
<style>
  @import url('https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800&family=Inter:wght@400;500;600&family=Spline+Sans+Mono:wght@500;600&display=swap');
  .lpa{
    --teal:#0E6B6B; --teal-deep:#0A4F4F; --teal-mid:#4E9A98; --teal-light:#A6CFCD;
    --paper:#EEF1F5; --ungrouped:#C3CCD6; --ink:#172A2F; --ink-soft:#54666C;
    --card:#FFFFFF; --line:#DDE4E9; --amber:#B5731A; --amber-bg:#FBEFD9; --good:#2E7D6B; --good-bg:#E2F0EC;
    font-family:'Inter',ui-sans-serif,system-ui,sans-serif; color:var(--ink);
  }
  .lpa .lp-eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--teal);font-weight:700;margin-bottom:6px}
  .lpa .lp-h1{font-family:'Archivo',sans-serif;font-weight:700;font-size:24px;letter-spacing:-.02em;margin-bottom:4px;color:var(--ink)}
  .lpa .lp-lede{color:var(--ink-soft);font-size:14px;margin-bottom:20px}
  .lpa .lp-row{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;flex-wrap:wrap}

  .lpa .lp-btn{font-family:'Inter',sans-serif;font-weight:600;font-size:13.5px;padding:9px 18px;border-radius:9px;border:none;cursor:pointer;background:var(--teal);color:#fff;text-decoration:none;display:inline-block}
  .lpa .lp-btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}

  .lpa .lp-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
  .lpa .lp-sitecard{display:block;background:var(--card);border:1px solid var(--line);border-radius:13px;padding:18px 20px;text-decoration:none;color:var(--ink);transition:.12s}
  .lpa .lp-sitecard:hover{border-color:var(--teal-mid);box-shadow:0 2px 10px rgba(10,79,79,.06)}
  .lpa .lp-sitecard .nm{font-family:'Archivo',sans-serif;font-weight:700;font-size:16px;display:flex;align-items:center;justify-content:space-between;gap:8px}
  .lpa .lp-status{font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:3px 9px;border-radius:20px}
  .lpa .lp-status.live{background:var(--good-bg);color:var(--good)}
  .lpa .lp-status.onboarding{background:var(--amber-bg);color:var(--amber)}
  .lpa .lp-prog{height:7px;border-radius:5px;background:#EEF2F4;overflow:hidden;margin:12px 0 6px}
  .lpa .lp-prog > i{display:block;height:100%;background:var(--teal)}
  .lpa .lp-progtxt{font-size:11.5px;color:var(--ink-soft)}
  .lpa .lp-sigs{display:flex;gap:16px;margin-top:14px;flex-wrap:wrap}
  .lpa .lp-sig{display:flex;flex-direction:column}
  .lpa .lp-sig .v{font-family:'Spline Sans Mono',monospace;font-size:19px;font-weight:600;color:var(--teal-deep)}
  .lpa .lp-sig .v.warn{color:var(--amber)}
  .lpa .lp-sig .v.bad{color:#B5341A}
  .lpa .lp-sig .l{font-size:11px;color:var(--ink-soft)}

  .lpa .lp-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:18px}
  .lpa .lp-stat{background:var(--card);border:1px solid var(--line);border-radius:11px;padding:15px 16px;text-decoration:none;color:var(--ink);display:block}
  .lpa a.lp-stat:hover{border-color:var(--teal-mid)}
  .lpa .lp-stat .sn{font-family:'Spline Sans Mono',monospace;font-size:24px;font-weight:600;color:var(--teal-deep)}
  .lpa .lp-stat .sn.bad{color:#B5341A}
  .lpa .lp-stat .sl{font-size:11.5px;color:var(--ink-soft);margin-top:2px}
  .lpa .lp-card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px 20px;margin-bottom:16px}
  .lpa .lp-card h3{font-family:'Archivo',sans-serif;font-size:14px;font-weight:700;margin-bottom:12px}
  .lpa .lp-funnel{display:flex;gap:6px;align-items:flex-end;height:90px}
  .lpa .lp-funnel .fb{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;justify-content:flex-end}
  .lpa .lp-funnel .bar{width:100%;background:var(--teal-light);border-radius:4px 4px 0 0;min-height:3px}
  .lpa .lp-funnel .fl{font-size:10px;color:var(--ink-soft);text-align:center}
  .lpa .lp-funnel .fv{font-family:'Spline Sans Mono',monospace;font-size:12px;font-weight:600;color:var(--teal-deep)}
  .lpa .lp-srow{display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid #EEF2F4;font-size:13px}
  .lpa .lp-srow:last-child{border-bottom:none}

  /* Standard page header (x-lp.page-header): titles left, site-scope indicator + one action right. */
  .lpa .lp-pagehead{align-items:flex-start}
  .lpa .lp-pagehead-titles{min-width:0}
  .lpa .lp-pagehead-aside{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .lpa .lp-pagehead-meta{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .lpa .lp-scope{display:inline-flex;align-items:center;gap:7px;padding:5px 11px 5px 7px;border:1px solid var(--line);border-radius:20px;background:var(--card);font-size:12px;font-weight:600;color:var(--ink-soft)}
  .lpa .lp-scope-logo{height:18px;width:auto;max-width:70px;object-fit:contain;border-radius:3px}
  .lpa .lp-scope-dot{width:7px;height:7px;border-radius:50%;background:var(--teal-mid)}
  .lpa .lp-scope-name{color:var(--ink);font-weight:700}

  /* Standard empty state (x-lp.empty): title + guidance + a named next action — never a dead end. */
  .lpa .lp-empty{padding:30px;text-align:center;color:var(--ink-soft);font-size:14px}
  .lpa .lp-empty-title{font-family:'Archivo',sans-serif;font-weight:700;font-size:15px;color:var(--ink);margin-bottom:4px}
  .lpa .lp-empty-body{max-width:420px;margin:0 auto}
  .lpa .lp-empty-cta{margin-top:16px}
</style>
