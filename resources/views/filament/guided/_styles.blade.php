{{-- House pattern: lp- inline styles, no theme build. Scoped to lp- prefixed classes so the
     Filament panel chrome is untouched (no global body/* reset). Mirrors launchpad-guided-interface.html. --}}
<style>
  @import url('https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=Inter:wght@400;500;600&family=Spline+Sans+Mono:wght@400;500;600&display=swap');
  .lp-scope{
    --teal:#0E6B6B; --teal-deep:#0A4F4F; --teal-mid:#4E9A98; --teal-light:#A6CFCD;
    --paper:#EEF1F5; --ungrouped:#C3CCD6; --ink:#172A2F; --ink-soft:#54666C;
    --card:#FFFFFF; --line:#DDE4E9; --amber:#B5731A; --amber-bg:#FBEFD9;
    --good:#2E7D6B; --good-bg:#E2F0EC;
    font-family:'Inter',ui-sans-serif,system-ui,sans-serif; color:var(--ink); font-size:14px; line-height:1.5;
  }
  .lp-scope .lp-mono{font-family:'Spline Sans Mono',monospace;font-variant-numeric:tabular-nums}
  .lp-shell{display:flex;min-height:560px;border:1px solid var(--line);border-radius:14px;overflow:hidden;background:var(--paper)}

  .lp-rail{width:248px;flex:none;background:var(--teal-deep);color:#DCEBEA;padding:24px 20px;display:flex;flex-direction:column;gap:26px}
  .lp-brand{font-family:'Archivo',sans-serif;font-weight:800;font-size:19px;letter-spacing:-.02em;color:#fff;display:flex;align-items:center;gap:9px}
  .lp-brand .dot{width:11px;height:11px;background:var(--teal-light);border-radius:2px;transform:rotate(45deg)}
  .lp-railsub{font-size:11px;letter-spacing:.13em;text-transform:uppercase;color:var(--teal-light);font-weight:600}
  .lp-steps{display:flex;flex-direction:column;position:relative}
  .lp-step{display:flex;gap:13px;align-items:flex-start;padding:11px 0;position:relative;color:#9FC3C0;text-decoration:none}
  .lp-step .num{width:26px;height:26px;flex:none;border-radius:50%;border:1.5px solid #3C7C7A;display:flex;align-items:center;justify-content:center;font-family:'Spline Sans Mono',monospace;font-size:12px;font-weight:600;background:var(--teal-deep);z-index:2}
  .lp-step .lbl{font-weight:600;font-size:13.5px;padding-top:3px;color:#CFE3E1}
  .lp-step .lbl-sub{font-size:11.5px;color:#7FA8A5;font-weight:400}
  .lp-step.done .num{background:var(--teal-mid);border-color:var(--teal-mid);color:#fff}
  .lp-step.active .num{background:var(--teal-light);border-color:var(--teal-light);color:var(--teal-deep)}
  .lp-step.active .lbl{color:#fff}
  .lp-step.locked{opacity:.5;cursor:not-allowed}
  .lp-step .conn{position:absolute;left:12.5px;top:30px;bottom:-8px;width:1.5px;background:#3C7C7A;z-index:1}
  .lp-step:last-of-type .conn{display:none}
  .lp-railfoot{margin-top:auto;border-top:1px solid #2C5C5A;padding-top:18px}
  .lp-grow{display:flex;gap:13px;align-items:center;padding:11px 13px;border-radius:8px;color:#CFE3E1;font-weight:600;background:rgba(255,255,255,.04);text-decoration:none}
  .lp-grow.active{background:var(--teal-mid);color:#fff}
  .lp-grow.locked{opacity:.5}
  .lp-grow .ic{width:26px;height:26px;flex:none;display:flex;align-items:center;justify-content:center;font-size:15px}

  .lp-main{flex:1;padding:32px 40px;max-width:760px}
  .lp-eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--teal);font-weight:700;margin-bottom:8px}
  .lp-h1{font-family:'Archivo',sans-serif;font-weight:700;font-size:25px;letter-spacing:-.02em;margin-bottom:6px;color:var(--ink)}
  .lp-lede{color:var(--ink-soft);font-size:14.5px;margin-bottom:24px;max-width:560px}

  .lp-card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px 22px;margin-bottom:16px}
  .lp-card h3{font-family:'Archivo',sans-serif;font-size:15px;font-weight:700;margin-bottom:4px}
  .lp-card .hint{color:var(--ink-soft);font-size:13px;margin-bottom:14px}
  .lp-field{margin-bottom:14px}
  .lp-field label{display:block;font-size:12px;font-weight:600;color:var(--ink-soft);margin-bottom:5px}
  .lp-input{width:100%;border:1px solid var(--line);border-radius:8px;padding:10px 12px;font-family:inherit;font-size:14px;background:#FBFCFD;color:var(--ink)}

  .lp-chips{display:flex;flex-wrap:wrap;gap:8px}
  .lp-chip{display:inline-flex;align-items:center;gap:7px;background:var(--good-bg);color:var(--teal-deep);border:1px solid #BFDED7;border-radius:20px;padding:6px 13px;font-size:13px;font-weight:600}
  .lp-chip.home{background:var(--teal);color:#fff;border-color:var(--teal)}
  .lp-chip .x{opacity:.5;font-weight:400}

  .lp-sug{display:flex;align-items:center;gap:11px;padding:11px 13px;border:1px solid var(--line);border-radius:9px;margin-bottom:8px;cursor:pointer;background:#FBFCFD}
  .lp-sug .box{width:19px;height:19px;flex:none;border:1.5px solid var(--ungrouped);border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff}
  .lp-sug.on{border-color:var(--teal);background:#F3F9F8}
  .lp-sug.on .box{background:var(--teal);border-color:var(--teal)}
  .lp-sug .nm{font-weight:600;font-size:13.5px}
  .lp-sug .why{color:var(--ink-soft);font-size:12px}

  .lp-tier{margin-bottom:14px}
  .lp-tierhd{display:flex;align-items:center;gap:9px;margin-bottom:7px}
  .lp-swatch{width:11px;height:11px;border-radius:3px;flex:none}
  .lp-tiernm{font-size:12px;font-weight:700;letter-spacing:.02em}
  .lp-tiercount{color:var(--ink-soft);font-size:12px}
  .lp-townrow{display:flex;flex-wrap:wrap;gap:7px}
  .lp-town{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;padding:5px 11px;border-radius:7px;border:1px solid var(--line);background:#FBFCFD;cursor:pointer}
  .lp-town.sel{background:#F3F9F8;border-color:var(--teal-mid);color:var(--teal-deep);font-weight:600}
  .lp-town .pg{font-size:10px;color:var(--teal)}

  .lp-silo{border:1px solid var(--line);border-radius:11px;margin-bottom:12px;overflow:hidden;background:var(--card)}
  .lp-silohd{display:flex;align-items:center;gap:11px;padding:14px 17px;background:#F7FAFB;border-bottom:1px solid var(--line)}
  .lp-silohd .spine{width:5px;height:34px;border-radius:3px;flex:none}
  .lp-silohd .nm{font-family:'Archivo',sans-serif;font-weight:700;font-size:15px}
  .lp-silohd .meta{color:var(--ink-soft);font-size:12px}
  .lp-silohd .vol{margin-left:auto;font-family:'Spline Sans Mono',monospace;font-weight:600;color:var(--teal-deep)}
  .lp-rows{padding:8px 17px 14px}
  .lp-prow{display:grid;grid-template-columns:1fr 64px 92px;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #EEF2F4}
  .lp-prow:last-child{border-bottom:none}
  .lp-prow .pnm{font-weight:600;font-size:13.5px}
  .lp-prow.child .pnm{font-weight:400;font-size:13px;color:var(--ink-soft);padding-left:18px}
  .lp-prow.subhub .pnm{color:var(--teal-deep)}
  .lp-prow .pvol{font-family:'Spline Sans Mono',monospace;font-size:12.5px;text-align:right;color:var(--ink-soft)}
  .lp-tag{font-size:10px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;text-align:center;padding:3px 0;border-radius:5px}
  .lp-tag.own{background:var(--good-bg);color:var(--good)}
  .lp-tag.fold{background:#EEF2F4;color:var(--ink-soft)}
  .lp-tag.hub{background:#E7EFEF;color:var(--teal-deep)}

  .lp-flag{display:flex;align-items:center;gap:13px;background:var(--amber-bg);border:1px solid #ECD6A8;border-radius:10px;padding:13px 16px;margin-bottom:10px}
  .lp-flag .fic{width:24px;height:24px;flex:none;border-radius:50%;background:var(--amber);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}
  .lp-flag .ftx{flex:1}
  .lp-flag .ftx .fsub{font-size:12px;color:var(--ink-soft)}
  .lp-fbtns{display:flex;gap:7px}
  .lp-mini{font-size:12px;font-weight:600;padding:6px 13px;border-radius:7px;border:1px solid var(--line);background:#fff;cursor:pointer;color:var(--ink)}
  .lp-mini.primary{background:var(--teal);color:#fff;border-color:var(--teal)}

  .lp-plan{border:1px solid var(--line);border-radius:11px;padding:16px 19px;margin-bottom:11px;background:#FBFCFD}
  .lp-plan .ptit{font-family:'Archivo',sans-serif;font-weight:700;font-size:14.5px;margin-bottom:3px}
  .lp-plan .ppages{font-size:13px;color:var(--ink);margin-bottom:6px}
  .lp-plan .pcov{font-size:12px;color:var(--ink-soft)}

  .lp-tog{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #EEF2F4}
  .lp-tog:last-child{border-bottom:none}
  .lp-tog .tnm{font-weight:600;font-size:13.5px}
  .lp-tog .tsub{font-size:12px;color:var(--ink-soft)}
  .lp-switch{margin-left:auto;width:40px;height:23px;border-radius:12px;background:var(--teal);position:relative;flex:none;cursor:pointer;border:none}
  .lp-switch::after{content:"";position:absolute;top:2.5px;right:2.5px;width:18px;height:18px;border-radius:50%;background:#fff}
  .lp-switch.off{background:var(--ungrouped)}
  .lp-switch.off::after{left:2.5px;right:auto}

  .lp-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin-bottom:18px}
  .lp-stat{background:var(--card);border:1px solid var(--line);border-radius:11px;padding:16px 18px}
  .lp-stat .sn{font-family:'Spline Sans Mono',monospace;font-size:26px;font-weight:600;color:var(--teal-deep);letter-spacing:-.01em}
  .lp-stat .sl{font-size:12px;color:var(--ink-soft);font-weight:500;margin-top:2px}
  .lp-q{display:flex;align-items:center;gap:12px;padding:12px 15px;border:1px solid var(--line);border-radius:9px;margin-bottom:8px;background:#FBFCFD}
  .lp-q .qd{width:9px;height:9px;border-radius:50%;flex:none}
  .lp-q .qn{font-weight:600;font-size:13.5px}
  .lp-q .qs{font-size:12px;color:var(--ink-soft)}
  .lp-q .qr{margin-left:auto;display:flex;align-items:center;gap:8px}
  .lp-news{display:flex;gap:11px;padding:11px 0;border-bottom:1px solid #EEF2F4}
  .lp-news:last-child{border-bottom:none}
  .lp-news .silotag{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--teal);background:#E7EFEF;padding:3px 8px;border-radius:5px;flex:none;height:fit-content;margin-top:1px}
  .lp-news .ntx b{font-weight:600;font-size:13.5px}
  .lp-news .ntx .nmeta{font-size:11.5px;color:var(--ink-soft)}

  .lp-foot{display:flex;align-items:center;gap:14px;margin-top:8px;padding-top:18px;border-top:1px solid var(--line)}
  .lp-btn{font-family:'Inter',sans-serif;font-weight:600;font-size:14px;padding:11px 22px;border-radius:9px;border:none;cursor:pointer;background:var(--teal);color:#fff}
  .lp-btn:disabled{background:var(--ungrouped);cursor:not-allowed}
  .lp-btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--line)}
  .lp-gate{font-size:12.5px;color:var(--amber);font-weight:600}
  .lp-gate.ok{color:var(--good)}
  .lp-pill{font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:3px 9px;border-radius:20px;background:var(--good-bg);color:var(--good)}
  .lp-empty{padding:40px;text-align:center;color:var(--ink-soft);font-size:14px}
</style>
