<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<style>
    :root { --brand: {{ $brand['primary'] }}; --accent: {{ $brand['accent'] }}; }
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; background: #f4f5f7; color: #1f2933; }
    .wrap { max-width: 680px; margin: 0 auto; padding: 24px 16px 48px; }
    .brandbar { display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: var(--brand); color: #fff; border-radius: 14px 14px 0 0; }
    .brandbar img { height: 34px; width: auto; max-width: 160px; background: #fff; border-radius: 6px; padding: 3px; }
    .brandbar .name { font-weight: 700; font-size: 17px; }
    .card { background: #fff; border-radius: 0 0 14px 14px; padding: 22px 20px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .card.solo { border-radius: 14px; }
    h1 { font-size: 20px; margin: 0 0 6px; }
    p { line-height: 1.5; }
    .sub { color: #52606d; margin: 0 0 14px; font-size: 14.5px; }
    .secure { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: #3e4c59; background: #eef2f6; border-radius: 999px; padding: 4px 10px; margin: 0 0 18px; }
    .resume { border-left: 4px solid var(--accent); background: #f7fbfd; padding: 10px 14px; border-radius: 8px; font-size: 14px; margin: 0 0 18px; }
    .progress { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px; margin: 0 0 20px; }
    .progress div { font-size: 12.5px; padding: 8px 10px; border-radius: 8px; background: #f0f3f6; color: #52606d; }
    .progress .filled { background: #e6f6ee; color: #146c43; }
    .progress .thin { background: #fff6e0; color: #8a5a00; }
    .progress span { display: block; font-size: 11px; opacity: .8; text-transform: capitalize; }
    .chat { display: flex; flex-direction: column; gap: 8px; margin: 0 0 16px; }
    .msg { max-width: 82%; padding: 10px 14px; border-radius: 12px; font-size: 15px; line-height: 1.45; white-space: pre-wrap; }
    .msg.assistant { align-self: flex-start; background: #f0f3f6; }
    .msg.owner, .msg.operator { align-self: flex-end; background: color-mix(in srgb, var(--accent) 18%, #fff); }
    .msg.current { background: color-mix(in srgb, var(--brand) 10%, #fff); border: 1px solid color-mix(in srgb, var(--brand) 35%, #fff); }
    label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 6px; }
    textarea { width: 100%; min-height: 110px; resize: vertical; border: 1px solid #cbd2d9; border-radius: 8px; padding: 10px 12px; font-size: 15px; font-family: inherit; }
    .btn { display: inline-block; margin-top: 12px; background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 12px 18px; font-size: 15px; font-weight: 600; cursor: pointer; }
    .btn.secondary { background: #fff; color: #1f2933; border: 1px solid #cbd2d9; font-weight: 500; }
    .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .retry { border: 1px solid #f0c36d; background: #fff8e6; border-radius: 9px; padding: 10px 14px; font-size: 14px; margin: 0 0 12px; }
    .err { color: #c81e1e; font-size: 13px; margin-top: 4px; }
    .muted { color: #8695a3; font-size: 12.5px; }
    .foot { margin-top: 22px; padding-top: 14px; border-top: 1px solid #e4e7eb; font-size: 12.5px; color: #8695a3; }
</style>
