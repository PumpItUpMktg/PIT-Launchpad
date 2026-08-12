<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Capture">
<link rel="manifest" href="/capture/manifest.webmanifest">
<link rel="apple-touch-icon" href="/capture-icon.svg">
<link rel="icon" href="/capture-icon.svg" type="image/svg+xml">
<title>Job Capture</title>
<style>@verbatim
  :root { --bg:#0f172a; --panel:#1e293b; --line:#334155; --ink:#f1f5f9; --muted:#94a3b8; --sky:#38bdf8; --green:#22c55e; --amber:#f59e0b; }
  * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
  html,body { margin:0; height:100%; }
  body { background:var(--bg); color:var(--ink); font:16px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left); }
  header { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--line); position:sticky; top:0; background:var(--bg); z-index:5; }
  header .date { font-size:14px; color:var(--muted); }
  .badge { font-size:13px; font-weight:700; padding:4px 10px; border-radius:99px; background:rgba(245,158,11,.16); color:var(--amber); }
  .badge.zero { background:rgba(148,163,184,.16); color:var(--muted); }
  main { padding:16px; max-width:640px; margin:0 auto; }
  .screen[hidden] { display:none; }
  h1 { font-size:20px; margin:4px 0 16px; }
  label { display:block; font-size:13px; color:var(--muted); margin:14px 0 6px; }
  input[type=text], input[type=tel], textarea { width:100%; background:var(--panel); border:1px solid var(--line); border-radius:12px; color:var(--ink); padding:14px; font-size:16px; }
  textarea { min-height:120px; resize:vertical; }
  .btn { display:block; width:100%; text-align:center; padding:16px; border-radius:14px; border:0; font-size:17px; font-weight:700; background:var(--sky); color:#04263a; margin-top:18px; }
  .btn:disabled { opacity:.5; }
  .btn.ghost { background:transparent; border:1px solid var(--line); color:var(--ink); }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:14px; margin-bottom:12px; }
  .card h3 { margin:0 0 4px; font-size:16px; }
  .card .meta { font-size:13px; color:var(--muted); }
  .chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
  .chip { font-size:12px; font-weight:600; padding:2px 9px; border-radius:99px; background:rgba(56,189,248,.14); color:var(--sky); }
  .slots { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
  .slot { position:relative; aspect-ratio:1; border:2px dashed var(--line); border-radius:14px; overflow:hidden; display:flex; align-items:center; justify-content:center; color:var(--muted); }
  .slot.filled { border-style:solid; border-color:var(--sky); }
  .slot img { width:100%; height:100%; object-fit:cover; }
  .slot input { position:absolute; inset:0; opacity:0; }
  .slot .plus { font-size:34px; }
  .slot .star { position:absolute; top:6px; left:6px; font-size:16px; }
  .subtle { color:var(--muted); font-size:13px; }
  .center { text-align:center; }
  .done { text-align:center; padding:60px 20px; }
  .done .check { font-size:64px; color:var(--green); }
  .row { display:flex; gap:10px; }
  .row .btn { margin-top:0; }
  .toast { position:fixed; left:50%; bottom:24px; transform:translateX(-50%); background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:12px 18px; font-size:14px; box-shadow:0 8px 30px rgba(0,0,0,.4); }
  .toast[hidden] { display:none; }
@endverbatim</style>
</head>
<body>
<header>
  <span class="date" id="today"></span>
  <span class="badge zero" id="pending">0 queued</span>
</header>

<main>
  <!-- LOGIN -->
  <section class="screen" id="screen-login" hidden>
    <h1>Sign in to Capture</h1>
    <p class="subtle">Enter the code from your invite text. Your phone stays signed in after this.</p>
    <label for="device">Device</label>
    <input type="text" id="device" placeholder="Device id" autocomplete="off">
    <button class="btn ghost" id="send-code">Send me a code</button>
    <label for="code">6-digit code</label>
    <input type="tel" id="code" inputmode="numeric" maxlength="6" placeholder="••••••">
    <button class="btn" id="sign-in">Sign in</button>
    <p class="subtle center" id="login-msg"></p>
  </section>

  <!-- JOB LIST -->
  <section class="screen" id="screen-list" hidden>
    <h1 id="hello">Today’s jobs</h1>
    <div id="jobs"></div>
    <p class="subtle center" id="jobs-empty" hidden>No assigned jobs. Tap “New job” for a walk-in.</p>
    <button class="btn" id="new-job">+ New job</button>
    <button class="btn ghost" id="sign-out">Sign out</button>
  </section>

  <!-- CAPTURE -->
  <section class="screen" id="screen-capture" hidden>
    <h1 id="capture-title">New job</h1>
    <div id="joby-fields"></div>

    <label>Photos <span class="subtle">(tap to shoot — first is the featured photo)</span></label>
    <div class="slots" id="slots"></div>

    <div id="manual-fields">
      <label for="client">Customer name <span class="subtle">(optional)</span></label>
      <input type="text" id="client" placeholder="First name + last initial" autocomplete="off">
    </div>

    <label for="desc">What did you do?</label>
    <textarea id="desc" placeholder="A sentence or two — what was the problem and how you fixed it. The more detail, the better the write-up."></textarea>

    <button class="btn" id="submit">Submit</button>
    <button class="btn ghost" id="cancel">Cancel</button>
    <p class="subtle center" id="gps-msg"></p>
  </section>

  <!-- DONE -->
  <section class="screen" id="screen-done" hidden>
    <div class="done">
      <div class="check">✓</div>
      <p>Saved. It’ll upload when you’re online.</p>
      <button class="btn" id="done-ok">Done</button>
    </div>
  </section>
</main>

<div class="toast" id="toast" hidden></div>

<script>@verbatim
(() => {
  const API = '/capture/api';
  const K = { token: 'jc_token', device: 'jc_device', tech: 'jc_tech' };
  const $ = (s) => document.querySelector(s);
  const token = () => localStorage.getItem(K.token);

  // ---- screens ----
  function show(name) {
    document.querySelectorAll('.screen').forEach((s) => (s.hidden = true));
    $('#screen-' + name).hidden = false;
    window.scrollTo(0, 0);
  }
  function toast(msg) {
    const t = $('#toast'); t.textContent = msg; t.hidden = false;
    clearTimeout(toast._t); toast._t = setTimeout(() => (t.hidden = true), 2600);
  }

  // ---- IndexedDB upload queue ----
  function openDB() {
    return new Promise((res, rej) => {
      const r = indexedDB.open('job-capture', 1);
      r.onupgradeneeded = () => r.result.createObjectStore('queue', { keyPath: 'localId' });
      r.onsuccess = () => res(r.result);
      r.onerror = () => rej(r.error);
    });
  }
  async function tx(mode, fn) {
    const db = await openDB();
    return new Promise((res, rej) => {
      const t = db.transaction('queue', mode);
      const out = fn(t.objectStore('queue'));
      t.oncomplete = () => res(out && out.result !== undefined ? out.result : out);
      t.onerror = () => rej(t.error);
    });
  }
  const qAdd = (item) => tx('readwrite', (s) => s.put(item));
  const qAll = () => tx('readonly', (s) => s.getAll());
  const qDel = (id) => tx('readwrite', (s) => s.delete(id));
  async function refreshBadge() {
    const n = (await qAll()).length;
    const el = $('#pending');
    el.textContent = n + ' queued';
    el.classList.toggle('zero', n === 0);
  }

  // ---- auth ----
  async function api(path, opts = {}) {
    opts.headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
    if (opts.json) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(opts.json); delete opts.json; }
    const t = token(); if (t) opts.headers['Authorization'] = 'Bearer ' + t;
    return fetch(API + path, opts);
  }
  function logout() {
    localStorage.removeItem(K.token); localStorage.removeItem(K.tech);
    $('#device').value = localStorage.getItem(K.device) || '';
    show('login');
  }

  // ---- image downscale (in-browser, before it ever enters the queue) ----
  function downscale(file, maxEdge = 2000) {
    return new Promise((res) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => {
        URL.revokeObjectURL(url);
        const scale = Math.min(1, maxEdge / Math.max(img.width, img.height));
        const w = Math.round(img.width * scale), h = Math.round(img.height * scale);
        const c = document.createElement('canvas'); c.width = w; c.height = h;
        c.getContext('2d').drawImage(img, 0, 0, w, h);
        res(c.toDataURL('image/jpeg', 0.82));
      };
      img.onerror = () => { URL.revokeObjectURL(url); res(null); };
      img.src = url;
    });
  }
  const b64 = (dataUrl) => (dataUrl || '').split(',')[1] || '';

  // ---- capture state ----
  let capture = null;
  function startCapture(job) {
    capture = { job: job || null, photos: [null, null, null] };
    $('#capture-title').textContent = job ? (job.client || 'Job') : 'New job';
    $('#manual-fields').hidden = !!job;
    const joby = $('#joby-fields'); joby.innerHTML = '';
    if (job) {
      joby.innerHTML = '<div class="card"><h3>' + (job.client || 'Customer') + '</h3>' +
        '<div class="meta">' + (job.city || '') + '</div>' +
        '<div class="chips">' + (job.job_types || []).map((t) => '<span class="chip">' + t + '</span>').join('') + '</div></div>';
    }
    $('#client').value = ''; $('#desc').value = ''; $('#gps-msg').textContent = '';
    renderSlots();
    show('capture');
    // Best-effort GPS — works offline; a walk-in with no fix defers its address to review.
    capture.lat = null; capture.lng = null;
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        (p) => { capture.lat = p.coords.latitude; capture.lng = p.coords.longitude; $('#gps-msg').textContent = '📍 Location captured'; },
        () => { $('#gps-msg').textContent = 'No GPS — the office will set the location.'; },
        { enableHighAccuracy: true, timeout: 8000 }
      );
    }
  }
  function renderSlots() {
    const wrap = $('#slots'); wrap.innerHTML = '';
    capture.photos.forEach((p, i) => {
      const slot = document.createElement('label');
      slot.className = 'slot' + (p ? ' filled' : '');
      slot.innerHTML = (p ? '<img src="' + p + '">' + (i === 0 ? '<span class="star">★</span>' : '') : '<span class="plus">+</span>');
      const input = document.createElement('input');
      input.type = 'file'; input.accept = 'image/*';
      input.setAttribute('capture', 'environment');   // camera-direct rear cam, not the gallery picker
      input.addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0]; if (!file) return;
        const d = await downscale(file); if (d) { capture.photos[i] = d; renderSlots(); }
      });
      slot.appendChild(input);
      wrap.appendChild(slot);
    });
  }

  async function submitCapture() {
    const photos = capture.photos.filter(Boolean).map((d, i) => ({ data: b64(d), filename: (i + 1) + '.jpg' }));
    const payload = {
      raw_description: $('#desc').value.trim(),
      primary_photo_index: 0,
      photos: photos,
    };
    if (!capture.job) { payload.client_name_display = $('#client').value.trim() || null; }
    if (capture.lat != null && capture.lng != null) { payload.lat = capture.lat; payload.lng = capture.lng; }

    const localId = 'c_' + Date.now() + '_' + Math.round(Math.random() * 1e6);
    await qAdd({ localId, payload });
    await refreshBadge();
    show('done');                 // optimistic — the tech never waits on the network
    drainQueue();
  }

  // ---- sync ----
  async function drainQueue() {
    if (!navigator.onLine || !token()) return;
    for (const item of await qAll()) {
      try {
        const r = await api('/jobs', { method: 'POST', json: item.payload });
        if (r.ok || r.status === 422) { await qDel(item.localId); }
        else if (r.status === 401) { logout(); return; }
      } catch (e) { /* offline / transient — stays queued */ }
    }
    await refreshBadge();
    loadJobs();
  }

  // ---- job list ----
  async function loadJobs() {
    if (!token()) return;
    let data;
    try { const r = await api('/jobs'); if (r.status === 401) return logout(); data = await r.json(); }
    catch (e) { return; }
    const wrap = $('#jobs'); wrap.innerHTML = '';
    const jobs = (data && data.jobs) || [];
    $('#jobs-empty').hidden = jobs.length > 0;
    jobs.forEach((job) => {
      const el = document.createElement('div');
      el.className = 'card';
      el.innerHTML = '<h3>' + (job.client || 'Job') + '</h3><div class="meta">' + (job.city || '') +
        (job.photo_count ? ' · ' + job.photo_count + ' photo' + (job.photo_count > 1 ? 's' : '') : '') + '</div>' +
        '<div class="chips">' + (job.job_types || []).map((t) => '<span class="chip">' + t + '</span>').join('') + '</div>';
      el.addEventListener('click', () => startCapture(job));
      wrap.appendChild(el);
    });
    const tech = localStorage.getItem(K.tech);
    if (tech) $('#hello').textContent = 'Hi ' + tech.split(' ')[0] + ' — today’s jobs';
  }

  // ---- wire up ----
  function boot() {
    $('#today').textContent = new Date().toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    refreshBadge();

    const url = new URL(location.href);
    const dev = url.searchParams.get('device');
    if (dev) localStorage.setItem(K.device, dev);
    $('#device').value = localStorage.getItem(K.device) || '';

    $('#send-code').addEventListener('click', async () => {
      const device = $('#device').value.trim(); if (!device) return toast('Enter your device id');
      localStorage.setItem(K.device, device);
      const r = await api('/auth/request-code', { method: 'POST', json: { device } });
      $('#login-msg').textContent = r.ok ? 'Code sent — check your texts.' : 'That device wasn’t recognised.';
    });
    $('#sign-in').addEventListener('click', async () => {
      const device = $('#device').value.trim(); const code = $('#code').value.trim();
      if (!device || !code) return toast('Enter the code');
      const r = await api('/auth/redeem', { method: 'POST', json: { device, code } });
      if (!r.ok) { $('#login-msg').textContent = 'That code didn’t work. Try again.'; return; }
      const { token: t, tech } = await r.json();
      localStorage.setItem(K.token, t); if (tech) localStorage.setItem(K.tech, tech);
      show('list'); loadJobs(); drainQueue();
    });
    $('#sign-out').addEventListener('click', logout);
    $('#new-job').addEventListener('click', () => startCapture(null));
    $('#cancel').addEventListener('click', () => { show('list'); loadJobs(); });
    $('#submit').addEventListener('click', submitCapture);
    $('#done-ok').addEventListener('click', () => { show('list'); loadJobs(); });

    window.addEventListener('online', drainQueue);

    if (token()) { show('list'); loadJobs(); drainQueue(); } else { show('login'); }

    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/capture/sw.js', { scope: '/capture' }).catch(() => {});
    }
  }
  boot();
})();
@endverbatim</script>
</body>
</html>
