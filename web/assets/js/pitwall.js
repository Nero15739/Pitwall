/* Pit Wall: the small amount of behaviour the server-rendered pages need. No framework.
   Theme, tooltips, segmented tabs, line charts, local dates, new-data banner, admin helpers. */
(() => {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const base = document.body.dataset.base || '';
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ---------------------------------------------------------------- driver identity (mirrors UI.php)
  const SHAPES = ['circle', 'square', 'triangle', 'diamond', 'star', 'x', 'rounded', 'plus'];
  const slotColor = s => (s == null ? 'var(--ai)' : `var(--s${s % 8})`);
  function shapePath(shape, r) {
    const k = r * 0.86, f = v => +v.toFixed(2);
    switch (shape) {
      case 'square': return { d: `M${f(-k)},${f(-k)}h${f(2 * k)}v${f(2 * k)}h${f(-2 * k)}Z` };
      case 'rounded': { const c = k * 0.45, s = 2 * (k - c);
        return { d: `M${f(-k + c)},${f(-k)}h${f(s)}q${f(c)},0 ${f(c)},${f(c)}v${f(s)}q0,${f(c)} ${f(-c)},${f(c)}h${f(-s)}q${f(-c)},0 ${f(-c)},${f(-c)}v${f(-s)}q0,${f(-c)} ${f(c)},${f(-c)}Z` }; }
      case 'triangle': return { d: `M0,${f(-r * 1.1)}L${f(r * 1.05)},${f(r * 0.75)}H${f(-r * 1.05)}Z` };
      case 'diamond': return { d: `M0,${f(-r * 1.15)}L${f(r * 1.15)},0L0,${f(r * 1.15)}L${f(-r * 1.15)},0Z` };
      case 'star': return { d: 'M' + Array.from({ length: 10 }, (_, i) => {
        const a = -Math.PI / 2 + (i * Math.PI) / 5, rr = i % 2 ? r * 0.5 : r * 1.15;
        return `${f(Math.cos(a) * rr)},${f(Math.sin(a) * rr)}`; }).join('L') + 'Z' };
      case 'x': return { d: `M${f(-k)},${f(-k)}L${f(k)},${f(k)}M${f(k)},${f(-k)}L${f(-k)},${f(k)}`, stroke: true };
      case 'plus': return { d: `M0,${f(-r)}V${f(r)}M${f(-r)},0H${f(r)}`, stroke: true };
      default: return { d: `M${f(-r)},0a${f(r)},${f(r)} 0 1,0 ${f(2 * r)},0a${f(r)},${f(r)} 0 1,0 ${f(-2 * r)},0` };
    }
  }
  /** SVG markup for a driver marker centred at 0,0. */
  function markerSvg(slot, r, ink = 'var(--ink)') {
    const sh = shapePath(SHAPES[(slot ?? 0) % 8], r), c = slotColor(slot);
    return sh.stroke
      ? `<path d="${sh.d}" stroke="${ink}" stroke-width="${r * 0.95}" stroke-linecap="square" fill="none"/><path d="${sh.d}" stroke="${c}" stroke-width="${r * 0.5}" stroke-linecap="square" fill="none"/>`
      : `<path d="${sh.d}" fill="${c}" stroke="${ink}" stroke-width="${r * 0.3}"/>`;
  }
  const markerIcon = (slot, size = 12) => `<svg class="mk" width="${size}" height="${size}" viewBox="-6.5 -6.5 13 13" aria-hidden="true">${markerSvg(slot, 4.4)}</svg>`;
  window.PitWall = { esc, slotColor, shapePath, markerSvg, markerIcon, SHAPES };

  // ---------------------------------------------------------------- theme
  document.addEventListener('click', e => {
    const t = e.target.closest('[data-theme-toggle]');
    if (!t) return;
    const d = document.documentElement;
    d.dataset.theme = d.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('pitwall-theme', d.dataset.theme); } catch { /* private mode */ }
  });

  // ---------------------------------------------------------------- local dates
  for (const t of $$('time[data-fmt]')) {
    const d = new Date(t.getAttribute('datetime'));
    if (isNaN(d)) continue;
    t.textContent = d.toLocaleDateString(undefined, t.dataset.fmt === 'short' ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' });
  }

  // ---------------------------------------------------------------- tooltip
  const tip = document.createElement('div');
  tip.className = 'tip';
  tip.setAttribute('role', 'tooltip');
  tip.hidden = true;
  document.body.append(tip);
  let tipFor = null;
  function showTip(el, x, y) {
    let c;
    try { c = JSON.parse(el.dataset.tip); } catch { return; }
    if (tipFor !== el) {
      tip.innerHTML = `<b>${esc(c.title)}</b>` + (c.lines || []).map(l => `<div class="tl"><strong>${esc(l.value)}</strong>${l.label ? `<span>${esc(l.label)}</span>` : ''}</div>`).join('') + (c.note ? `<div class="tl"><span>${esc(c.note)}</span></div>` : '');
      tipFor = el;
    }
    tip.hidden = false;
    placeTip(x, y);
  }
  function placeTip(x, y) {
    const pad = 14, w = tip.offsetWidth, h = tip.offsetHeight;
    let left = x + pad, top = y + pad;
    if (left + w > innerWidth - 8) left = x - w - pad;
    if (top + h > innerHeight - 8) top = y - h - pad;
    tip.style.left = Math.max(8, left) + 'px';
    tip.style.top = Math.max(8, top) + 'px';
  }
  const hideTip = () => { tip.hidden = true; tipFor = null; };
  window.PitWall.tip = { show: (html, x, y) => { tip.innerHTML = html; tipFor = null; tip.hidden = false; placeTip(x, y); }, hide: hideTip };
  document.addEventListener('pointermove', e => {
    const el = e.target.closest?.('[data-tip]');
    if (el) showTip(el, e.clientX, e.clientY);
    else if (tipFor) hideTip();
  }, { passive: true });
  document.addEventListener('focusin', e => {
    const el = e.target.closest?.('[data-tip]');
    if (!el) return;
    const r = el.getBoundingClientRect();
    showTip(el, r.left + r.width / 2, r.bottom);
  });
  document.addEventListener('focusout', hideTip);
  addEventListener('scroll', () => tipFor && hideTip(), { passive: true });

  // ---------------------------------------------------------------- segmented tabs → panes
  document.addEventListener('click', e => {
    const b = e.target.closest('.seg[data-seg] button[data-value]');
    if (!b) return;
    const seg = b.closest('.seg'), group = seg.dataset.seg, v = b.dataset.value;
    for (const x of $$('button', seg)) x.setAttribute('aria-pressed', String(x === b));
    for (const p of $$(`[data-pane="${CSS.escape(group)}"]`)) p.hidden = p.dataset.value !== v;
    document.dispatchEvent(new CustomEvent('pw:seg', { detail: { group, value: v } }));
  });

  // ---------------------------------------------------------------- season picker keeps the section
  const pick = $('[data-season-nav]');
  pick?.addEventListener('change', () => {
    const parts = location.pathname.slice(base.length).split('/').filter(Boolean);
    const section = parts[1] && parts[1] !== 'tracks' ? '/' + parts[1] : parts[1] === 'tracks' ? '/tracks' : '';
    location.href = `${base}/${encodeURIComponent(pick.value)}${section}`;
  });
  for (const s of $$('select[data-autosubmit]')) s.addEventListener('change', () => s.form?.requestSubmit());

  // ---------------------------------------------------------------- line charts
  function niceScale(lo, hi, count = 4) {
    if (!(hi > lo)) hi = lo + 1;
    const raw = (hi - lo) / count, p = 10 ** Math.floor(Math.log10(raw)), f = raw / p;
    const step = (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
    const a = Math.floor(lo / step) * step, b = Math.ceil(hi / step) * step, ticks = [];
    for (let v = a; v <= b + step / 1e6; v += step) ticks.push(+v.toFixed(10));
    return { lo: a, hi: b, ticks };
  }
  const FMT = {
    int: v => String(Math.round(v)),
    d1: v => v.toFixed(1),
    d2: v => v.toFixed(2),
    pace: v => `${v.toFixed(v < 10 ? 2 : 1)}%`,
  };

  function lineChart(el) {
    const c = JSON.parse(el.dataset.chart);
    const fmt = FMT[c.fmt] || FMT.int, n = c.labels.length, H = c.height || 280;
    const M = { t: 20, r: 16, b: 28, l: 46 };
    const hidden = new Set();
    let focus = null, active = null, width = 0;
    el.innerHTML = `<div class="chart-legend" role="group" aria-label="Series"></div>
      <div class="chart-plot" tabindex="0" role="application" aria-roledescription="chart" aria-label="${esc(c.aria || 'Chart')}. Use the left and right arrow keys to read each round."></div>`;
    el.style.minHeight = '';
    const legend = $('.chart-legend', el), plot = $('.chart-plot', el);
    legend.innerHTML = c.series.map(s => `<button type="button" class="chip legend" aria-pressed="true" data-name="${esc(s.name)}" style="--c:${slotColor(s.slot)}"><span class="swatch"></span>${markerIcon(s.slot)}${esc(s.name)}</button>`).join('');
    legend.addEventListener('click', e => {
      const b = e.target.closest('button'); if (!b) return;
      const name = b.dataset.name;
      hidden.has(name) ? hidden.delete(name) : hidden.add(name);
      b.setAttribute('aria-pressed', String(!hidden.has(name)));
      draw();
    });
    legend.addEventListener('pointerover', e => { const b = e.target.closest('button'); focus = b ? b.dataset.name : null; emphasise(); });
    legend.addEventListener('pointerleave', () => { focus = null; emphasise(); });

    let geo = null;
    function draw() {
      width = plot.clientWidth;
      if (!width) return;
      const vis = c.series.filter(s => !hidden.has(s.name));
      const vals = vis.flatMap(s => s.values).filter(v => v != null);
      const sc = vals.length ? niceScale(c.zero !== false ? Math.min(0, ...vals) : Math.min(...vals), Math.max(...vals)) : niceScale(0, 1);
      const iw = Math.max(0, width - M.l - M.r), ih = H - M.t - M.b;
      const x = i => M.l + (iw * (i + 0.5)) / n;
      const y = v => M.t + ih * (1 - (v - sc.lo) / (sc.hi - sc.lo));
      const every = Math.max(1, Math.ceil((n * 64) / Math.max(iw, 1)));
      geo = { x, y, vis, M, ih };
      let svg = `<svg width="${width}" height="${H}" role="img" aria-label="${esc(c.aria || '')}">`;
      if (c.yTitle) svg += `<text class="axis" x="${M.l - 8}" y="9" text-anchor="end">${esc(c.yTitle)}</text>`;
      for (const t of sc.ticks) svg += `<line class="${t === 0 && sc.lo < 0 ? 'zero' : 'grid'}" x1="${M.l}" x2="${width - M.r}" y1="${y(t)}" y2="${y(t)}"/><text class="axis" x="${M.l - 8}" y="${y(t)}" dy="0.32em" text-anchor="end">${esc(fmt(t))}</text>`;
      svg += `<line x1="${M.l}" x2="${width - M.r}" y1="${M.t + ih}" y2="${M.t + ih}" stroke="var(--ink)" stroke-width="3"/>`;
      c.labels.forEach((l, i) => { if (i % every === 0) svg += `<text class="axis" x="${x(i)}" y="${H - 6}" text-anchor="middle">${esc(l)}</text>`; });
      svg += `<line class="xhair" x1="0" x2="0" y1="${M.t}" y2="${M.t + ih}" stroke="var(--ink)" stroke-width="2" stroke-dasharray="4 3" visibility="hidden"/>`;
      for (const s of vis) {
        const pts = s.values.map((v, i) => (v == null ? null : `${x(i).toFixed(1)},${y(v).toFixed(1)}`)).filter(Boolean);
        svg += `<g class="series" data-name="${esc(s.name)}"><path d="M${pts.join('L')}" fill="none" stroke="${slotColor(s.slot)}" stroke-width="3" stroke-linejoin="miter" stroke-linecap="square"/>`;
        s.values.forEach((v, i) => { if (v != null) svg += `<g class="pt" data-i="${i}" transform="translate(${x(i).toFixed(1)},${y(v).toFixed(1)})">${markerSvg(s.slot, 4.6)}</g>`; });
        svg += '</g>';
      }
      svg += `<rect class="hit" x="${M.l}" y="${M.t}" width="${iw}" height="${ih}" fill="transparent"/></svg><div class="chart-tip" hidden></div>`;
      plot.innerHTML = svg;
      emphasise();
      mark();
    }
    function emphasise() {
      for (const g of $$('.series', plot)) g.style.opacity = focus && focus !== g.dataset.name ? '0.15' : '1';
    }
    function mark() {
      const xh = $('.xhair', plot), tipEl = $('.chart-tip', plot);
      if (!xh || !geo) return;
      for (const p of $$('.pt', plot)) {
        const on = active != null && +p.dataset.i === active;
        p.setAttribute('transform', p.getAttribute('transform').replace(/ scale\([^)]*\)/, '') + (on ? ' scale(1.35)' : ''));
      }
      if (active == null) { xh.setAttribute('visibility', 'hidden'); tipEl.hidden = true; return; }
      const xa = geo.x(active);
      xh.setAttribute('x1', xa); xh.setAttribute('x2', xa); xh.setAttribute('visibility', 'visible');
      const rows = geo.vis.filter(s => s.values[active] != null).map(s => ({ s, v: s.values[active] }))
        .sort((a, b) => (c.sortDesc === false ? a.v - b.v : b.v - a.v));
      if (!rows.length) { tipEl.hidden = true; return; }
      tipEl.innerHTML = `<b>${esc(c.titles[active])}</b>` + rows.map(r => `<div><i style="--c:${slotColor(r.s.slot)}"></i><strong>${esc(fmt(r.v))}</strong> <span>${esc(r.s.name)}</span></div>`).join('');
      tipEl.hidden = false;
      const w = tipEl.offsetWidth;
      tipEl.style.left = (xa + 14 + w > width ? xa - 14 - w : xa + 14) + 'px';
      tipEl.style.top = M.t + 'px';
    }
    plot.addEventListener('pointermove', e => {
      if (!e.target.closest('.hit') && !e.target.closest('.pt')) return;
      const r = plot.getBoundingClientRect(), px = e.clientX - r.left;
      const i = Math.max(0, Math.min(n - 1, Math.floor(((px - M.l) / Math.max(1, width - M.l - M.r)) * n)));
      if (i !== active) { active = i; mark(); }
    });
    plot.addEventListener('pointerleave', () => { active = null; mark(); });
    plot.addEventListener('keydown', e => {
      if (e.key === 'ArrowRight') active = active == null ? 0 : Math.min(n - 1, active + 1);
      else if (e.key === 'ArrowLeft') active = active == null ? n - 1 : Math.max(0, active - 1);
      else if (e.key === 'Escape') active = null;
      else return;
      e.preventDefault();
      mark();
    });
    plot.addEventListener('blur', () => { active = null; mark(); });
    new ResizeObserver(() => { if (plot.clientWidth !== width) draw(); }).observe(plot);
  }
  for (const el of $$('[data-chart]')) lineChart(el);

  // ---------------------------------------------------------------- new data banner
  const banner = $('[data-refresh]');
  const version = document.body.dataset.version;
  if (banner && version) {
    let lastCheck = Date.now(), stop = false;
    const check = async () => {
      if (document.hidden || stop || Date.now() - lastCheck < 30_000) return;
      lastCheck = Date.now();
      try {
        const r = await fetch(`${base}/api/v1/version`, { cache: 'no-store' });
        const j = await r.json();
        if (j.generated && j.generated !== version) { banner.hidden = false; stop = true; }
      } catch { /* offline: keep showing what we have */ }
    };
    setInterval(check, 60_000);
    document.addEventListener('visibilitychange', check);
    $('[data-reload]', banner)?.addEventListener('click', () => location.reload());
  }

  // ---------------------------------------------------------------- admin helpers
  document.addEventListener('submit', e => {
    const msg = e.target.dataset?.confirm;
    if (msg && !confirm(msg)) e.preventDefault();
  });
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    const src = document.getElementById(b.dataset.copy);
    try {
      await navigator.clipboard.writeText(src.value ?? src.textContent);
      const old = b.textContent; b.textContent = 'Copied'; setTimeout(() => (b.textContent = old), 1500);
    } catch { src.select?.(); }
  });
  for (const drop of $$('.drop')) {
    const input = $('input[type=file]', drop), list = $('.drop-list', drop);
    const show = () => {
      const files = [...input.files];
      list.innerHTML = files.map(f => `<li><span>${esc(f.name)}</span><span class="dim">${(f.size / 1024).toFixed(0)} KB</span></li>`).join('');
      drop.classList.toggle('has', files.length > 0);
    };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('over'); }));
    ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, () => drop.classList.remove('over')));
    drop.addEventListener('drop', e => { e.preventDefault(); input.files = e.dataTransfer.files; show(); });
  }
})();
