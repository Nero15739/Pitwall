/* Track page: the circuit from iRacing's official map with incident density painted along the
   racing line, every replay incident stacked outward like a pin, hotspot badges, and a sector
   view of the splits. Lap distance → SVG point: path.getPointAtLength(L * ((1 + offset + dir * pct) % 1)). */
(() => {
  'use strict';
  const root = document.getElementById('track-app');
  const dataEl = document.getElementById('track-data');
  if (!root || !dataEl || !window.PitWall) return;
  const D = JSON.parse(dataEl.textContent);
  const { esc, slotColor, markerSvg, markerIcon } = window.PitWall;

  const slotOf = n => (Object.prototype.hasOwnProperty.call(D.slots, n) ? D.slots[n] : null);
  const first = n => String(n).split(' ')[0];
  const s3 = x => (x == null ? '–' : x.toFixed(3));
  const lap = t => { if (t == null) return '–'; const m = Math.floor(t / 60); return `${m}:${(t - m * 60).toFixed(3).padStart(6, '0')}`; };
  const km = m => (m == null ? null : m >= 1000 ? `${(m / 1000).toFixed(2)} km` : `${Math.round(m)} m`);
  const where = pct => `${(pct * 100).toFixed(0)}% of the lap${D.lengthM ? ' · ' + km(pct * D.lengthM) : ''}`;
  const tipAttr = c => ` data-tip="${esc(JSON.stringify(c))}"`;
  const dtag = (name, ai) => `<span class="dtag">${markerIcon(ai ? null : slotOf(name))}<span class="dtag-n">${esc(name)}</span>${ai ? '<span class="ai-badge">AI</span>' : ''}</span>`;
  const seg = (act, opts, value, label) => `<div class="seg" role="group" aria-label="${esc(label)}">${opts.map(([v, t]) => `<button type="button" data-act="${act}" data-v="${esc(v)}" aria-pressed="${String(v) === String(value)}">${esc(t)}</button>`).join('')}</div>`;

  const S = { view: 'hotspots', scope: 'season', who: 'team', highlight: null, focus: null, split: null, driver: null, sector: null };
  const scopes = [
    ...(D.harvested.length > 1 ? D.harvested.map(r => [`r${r}`, `R${r}`]) : []),
    ['season', D.harvested.length > 1 ? 'Season' : 'This race'],
    ...(D.all ? [['all', 'All seasons']] : []),
  ];

  // ---------------------------------------------------------------- data for the current state
  function layer() {
    let sum, events = D.season?.events ?? [];
    if (S.scope === 'all') { sum = D.all; events = D.all?.events ?? []; }
    else if (S.scope.startsWith('r')) { sum = D.byRound[S.scope.slice(1)]; events = events.filter(e => e.round === +S.scope.slice(1)); }
    else sum = D.season;
    if (!sum) return null;
    const team = S.who === 'team';
    return {
      events: team ? events.filter(e => e.team) : events,
      density: team ? sum.density_team : sum.density_field,
      top: team ? sum.top_team : sum.top_field,
      n: team ? sum.n_team : sum.n_field,
    };
  }
  const split = () => D.splits.find(s => s.round === S.split) ?? D.splits[D.splits.length - 1] ?? null;
  const spDriver = sp => (sp ? sp.drivers.find(d => d.driver === S.driver) ?? sp.drivers[0] : null);
  const gapOf = (sp, best, k) => (best != null && sp.team_best[k] != null ? Math.max(0, best - sp.team_best[k]) : null);
  const maxGap = sp => Math.max(0.001, ...sp.drivers.filter(d => d.team).flatMap(d => d.best.map((b, k) => gapOf(sp, b, k) ?? 0)));
  const stepOf = (g, mg) => (g == null ? null : 2 + Math.round(Math.min(1, g / mg) * 5)); // the best sector still reads as coloured
  const gapText = g => (g == null ? '–' : g < 0.0005 ? 'best' : `+${g.toFixed(3)}`);

  // ---------------------------------------------------------------- geometry (measured once)
  let geo = null;
  function measure() {
    if (geo || !D.map) return geo;
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', D.map.viewBox);
    svg.style.cssText = 'position:absolute;left:-9999px;top:0;width:10px;height:10px;visibility:hidden';
    const p = document.createElementNS(ns, 'path');
    p.setAttribute('d', D.map.path);
    svg.append(p);
    document.body.append(svg);
    const L = p.getTotalLength(), N = 800;
    const at = pct => p.getPointAtLength(L * ((((1 + D.map.offset + D.map.direction * pct) % 1) + 1) % 1));
    const pts = Array.from({ length: N + 1 }, (_, k) => { const q = at(k / N); return { x: q.x, y: q.y }; });
    // shoelace sign: which perpendicular points away from the infield
    let area = 0;
    for (let k = 0; k < N; k++) area += pts[k].x * pts[k + 1].y - pts[k + 1].x * pts[k].y;
    const b = p.getBBox();
    svg.remove();
    const pad = Math.max(b.width, b.height) * 0.08;
    geo = { pts, N, outward: area > 0 ? -1 : 1, box: { x: b.x - pad, y: b.y - pad, w: b.width + 2 * pad, h: b.height + 2 * pad } };
    return geo;
  }
  function pointAt(pct) {
    const { pts, N, outward } = geo;
    const f = ((((pct % 1) + 1) % 1)) * N, k = Math.floor(f), t = f - k;
    const a = pts[k], b = pts[Math.min(N, k + 1)], c = pts[Math.max(0, k - 2)], d = pts[Math.min(N, k + 2)];
    const dx = d.x - c.x, dy = d.y - c.y, len = Math.hypot(dx, dy) || 1;
    return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t, nx: (-dy / len) * outward, ny: (dx / len) * outward };
  }
  const segPath = (from, to) => 'M' + geo.pts.slice(Math.floor(from * geo.N), Math.ceil(to * geo.N) + 1).map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join('L');

  // ---------------------------------------------------------------- the map
  function mapSvg(width) {
    const g = measure(), { box } = g, u = box.w / width, T = 11;
    const L = S.view === 'hotspots' ? layer() : null;
    const sp = S.view === 'sectors' ? split() : null;
    const out = [`<svg viewBox="${box.x} ${box.y} ${box.w} ${box.h}" role="img" aria-label="${esc(D.track)} map" style="aspect-ratio:${box.w} / ${box.h}">`];
    out.push(`<path d="${esc(D.map.path)}" fill="none" stroke="var(--ink)" stroke-width="${T + 6}" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>`);
    out.push(`<path d="${esc(D.map.path)}" fill="none" stroke="var(--card)" stroke-width="${T}" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>`);

    let labels = '';
    if (sp) {
      const d = spDriver(sp), mg = maxGap(sp), st = sp.sectors;
      st.forEach((from, k) => {
        const to = k + 1 < st.length ? st[k + 1] : 1;
        const g2 = gapOf(sp, d.best[k], k), step = stepOf(g2, mg);
        if (S.sector === k) out.push(`<path d="${segPath(from, to)}" fill="none" stroke="var(--ink)" stroke-width="${T + 10}" vector-effect="non-scaling-stroke" stroke-linecap="butt"/>`);
        out.push(`<path d="${segPath(from, to)}" fill="none" stroke="${step ? `var(--h${step})` : 'var(--muted)'}" stroke-width="${T}" vector-effect="non-scaling-stroke" stroke-linecap="butt" data-act="sector" data-v="${k}" style="cursor:pointer"/>`);
        const mid = pointAt((from + to) / 2), off = -(T / 2 + 26) * u, on = S.sector === k;
        labels += `<g transform="translate(${mid.x + mid.nx * off},${mid.y + mid.ny * off})" role="button" tabindex="0" data-act="sector" data-v="${k}" aria-pressed="${on}" aria-label="Sector ${k + 1}: ${esc(gapText(g2))}" style="cursor:pointer">
          <rect x="${-34 * u}" y="${-13 * u}" width="${68 * u}" height="${26 * u}" fill="${on ? 'var(--ink)' : 'var(--card)'}" stroke="var(--ink)" stroke-width="${2.5 * u}"/>
          <text text-anchor="middle" dy="0.35em" font-size="${11.5 * u}" font-weight="800" font-family="var(--mono)" fill="${on ? 'var(--paper)' : 'var(--ink)'}">S${k + 1} ${esc(gapText(g2))}</text></g>`;
      });
      for (const from of st) {
        const p = pointAt(from), r = (T / 2 + 6) * u;
        out.push(`<line x1="${p.x - p.nx * r}" y1="${p.y - p.ny * r}" x2="${p.x + p.nx * r}" y2="${p.y + p.ny * r}" stroke="var(--ink)" stroke-width="3" vector-effect="non-scaling-stroke"/>`);
      }
    } else if (L) {
      const maxD = Math.max(0, ...L.density);
      if (maxD > 0) {
        const per = g.N / L.density.length;
        L.density.forEach((d, i) => {
          if (d < maxD * 0.04) return;
          const pts = g.pts.slice(Math.floor(i * per), Math.floor((i + 1) * per) + 1);
          out.push(`<path d="M${pts.map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join('L')}" fill="none" stroke="var(--h${1 + Math.round((d / maxD) * 6)})" stroke-width="${T}" vector-effect="non-scaling-stroke" stroke-linecap="butt"/>`);
        });
      }
    }

    if (D.map.sf) out.push(`<path d="${esc(D.map.sf)}" fill="var(--ink)"/>`);
    else {
      const p = pointAt(0), r = (T / 2 + 6) * u;
      out.push(`<line x1="${p.x - p.nx * r}" y1="${p.y - p.ny * r}" x2="${p.x + p.nx * r}" y2="${p.y + p.ny * r}" stroke="var(--ink)" stroke-width="4" vector-effect="non-scaling-stroke"/>`);
    }
    out.push(labels);

    if (L) {
      // pins: sorted round the lap; markers within ~0.5% of a lap stack outward
      const evs = [...L.events].sort((a, b) => a.pct - b.pct);
      let anchor = -1, depth = 0;
      for (const e of evs) {
        if (anchor >= 0 && e.pct - anchor < 0.005) depth++; else { anchor = e.pct; depth = 0; }
        const p = pointAt(e.pct), off = (T / 2 + 8 + depth * 8) * u;
        const x = p.x + p.nx * off, y = p.y + p.ny * off;
        const hi = S.highlight === e.driver, dim = S.highlight != null && !hi;
        const tip = { title: `${e.driver}${e.ai ? ' (AI)' : ''}`, lines: [
          { value: e.kind === 'spin' ? 'Spun' : 'Off track', label: e.kind === 'spin' ? (e.off ? 'and left the circuit' : 'rolled backwards') : 'left the circuit' },
          { value: `Lap ${e.lap}`, label: `Round ${e.round}${e.season ? ' · ' + e.season : ''}` }, { value: where(e.pct) }] };
        let mark;
        if (hi) mark = `<g transform="translate(${x},${y})">${markerSvg(slotOf(e.driver), 5.2 * u)}</g>`;
        else if (e.kind === 'spin') mark = `<circle cx="${x}" cy="${y}" r="${3.6 * u}" fill="var(--card)" stroke="${e.ai ? 'var(--ai)' : 'var(--ink)'}" stroke-width="${2.2 * u}"/>`;
        else mark = `<circle cx="${x}" cy="${y}" r="${4 * u}" fill="${e.ai ? 'var(--ai)' : 'var(--ink)'}" stroke="var(--card)" stroke-width="${1.4 * u}"/>`;
        out.push(`<g opacity="${dim ? 0.18 : 1}"${tipAttr(tip)}><line x1="${p.x}" y1="${p.y}" x2="${x}" y2="${y}" stroke="var(--ink-3)" stroke-width="1" vector-effect="non-scaling-stroke"/><circle cx="${x}" cy="${y}" r="${11 * u}" fill="transparent"/>${mark}</g>`);
      }
      L.top.forEach((h, i) => {
        const p = pointAt(h.pct), off = -(T / 2 + 18) * u, x = p.x + p.nx * off, y = p.y + p.ny * off, s = 22 * u;
        out.push(`<g transform="translate(${x},${y})" data-act="hot" data-v="${i}" style="cursor:pointer">
          ${S.focus === i ? `<rect x="${-s * 0.85}" y="${-s * 0.85}" width="${s * 1.7}" height="${s * 1.7}" fill="var(--accent)" stroke="var(--ink)" stroke-width="${2.5 * u}"/>` : ''}
          <rect x="${-s / 2}" y="${-s / 2}" width="${s}" height="${s}" fill="var(--ink)"/>
          <text text-anchor="middle" dy="0.36em" fill="var(--accent)" font-size="${13 * u}" font-weight="800" font-family="var(--mono)">${i + 1}</text></g>`);
      });
    }
    out.push('</svg>');
    return out.join('');
  }

  // ---------------------------------------------------------------- panels
  function mainPanel() {
    const L = S.view === 'hotspots' ? layer() : null, sp = split(), d = spDriver(sp);
    const sectors = S.view === 'sectors' && sp;
    const title = sectors ? 'Sector splits' : 'Crash hotspots';
    const sub = sectors ? `${d.driver}${d.ai ? ' (AI)' : ''} · best time in each sector against the team’s best. Pick a sector to compare everyone.`
      : L ? `${L.n} off-tracks and spins found in the replay${S.who === 'team' ? ', team drivers' : ', whole field'}, stacked where they happened`
      : 'Where incidents happened on the lap, from the race replay';
    let actions = '';
    if (D.splits.length) actions += seg('view', [['hotspots', 'Hotspots'], ['sectors', 'Sectors']], S.view, 'Map view');
    if (!sectors && L) {
      if (scopes.length > 1) actions += seg('scope', scopes, S.scope, 'Races');
      actions += seg('who', [['team', 'Team'], ['field', 'Whole field']], S.who, 'Drivers');
    } else if (sectors && D.splits.length > 1) {
      actions += seg('split', D.splits.map(s => [s.round, `R${s.round}`]), sp.round, 'Race');
    }

    let under = '';
    if (sectors) {
      const mg = maxGap(sp);
      under = `<div class="map-legend"><span>Team best <span class="ramp">${[2, 3, 4, 5, 6, 7].map(s => `<i style="background:var(--h${s})"></i>`).join('')}</span> +${mg.toFixed(3)}s</span>
        <span><i style="width:3px;height:14px;background:var(--ink);display:inline-block"></i> Sector line</span></div>
        <div class="chips map-controls" role="group" aria-label="Driver"><span class="chips-l">Driver</span>${sp.drivers.map(x => `<button type="button" class="chip" data-act="driver" data-v="${esc(x.driver)}" aria-pressed="${x.driver === d.driver}">${markerIcon(x.ai ? null : slotOf(x.driver))}${esc(first(x.driver))}${x.ai ? ' (AI)' : ''}</button>`).join('')}</div>`;
    } else if (L) {
      const who = [...new Set(L.events.filter(e => e.team).map(e => e.driver))].sort();
      under = `<div class="map-legend"><span>Fewer <span class="ramp">${[1, 2, 3, 4, 5, 6, 7].map(s => `<i style="background:var(--h${s})"></i>`).join('')}</span> more incidents</span>
        <span><span class="dot"></span>Off track</span><span><span class="ring"></span>Spin</span>${S.who === 'field' ? '<span><span class="dot ai"></span>AI driver</span>' : ''}
        <span><span class="hnum" style="width:18px;height:18px;font-size:10px">1</span>Hotspot</span></div>`
        + (who.length ? `<div class="chips map-controls" role="group" aria-label="Highlight a driver"><span class="chips-l">Highlight</span>${who.map(n => `<button type="button" class="chip" data-act="hl" data-v="${esc(n)}" aria-pressed="${S.highlight === n}">${markerIcon(slotOf(n))}${esc(first(n))}</button>`).join('')}</div>` : '');
    } else {
      under = `<div class="pending"><strong>No crash locations or splits yet.</strong>
        <p class="muted" style="margin:6px 0 10px">Both come from the race replay. On the PC with iRacing, open the replay and run the harvester; with an API key set up it uploads here by itself.</p>
        ${D.pending.map(p => `<div style="margin-top:6px"><code>npm run harvest -- --open ${p.subsession}</code> <span class="dim">Round ${p.round}</span></div>`).join('')}</div>`;
    }
    const map = D.map ? `<div class="map" data-map style="max-width:${Math.round((620 * measure().box.w) / measure().box.h)}px"></div>`
      : '<div class="map-empty">Track map unavailable for this layout.</div>';
    return `<section class="panel"><header class="panel-h"><div class="panel-t"><h2>${title}</h2><p>${esc(sub)}</p></div>${actions ? `<div class="panel-a">${actions}</div>` : ''}</header>
      <div class="panel-b">${map}${under}</div></section>`;
  }

  function sidePanel() {
    const sp = split(), d = spDriver(sp);
    if (S.view === 'sectors' && sp) {
      const a = sp.analysis.drivers.find(x => x.driver === d.driver);
      if (S.sector == null) {
        const rows = d.best.map((b, k) => {
          const g = gapOf(sp, b, k);
          const tag = a?.weakest === k ? ' <span class="dim">weakest</span>' : a?.strongest === k ? ' <span class="dim">strongest</span>' : '';
          return `<tr class="click" data-act="sector" data-v="${k}"><td>S${k + 1}${tag}</td><td class="num strong">${s3(b)}</td><td class="num ${g != null && g < 0.0005 ? 'good' : 'dim'}">${gapText(g)}</td><td class="num dim">${s3(d.avg[k])}</td></tr>`;
        }).join('');
        return `<section class="panel"><header class="panel-h"><div class="panel-t"><h2>${esc(first(d.driver))}’s splits</h2><p>Best and average time in each sector</p></div></header>
          <div class="panel-b"><div class="scroll"><table class="tbl compact"><thead><tr><th>Sector</th><th class="num">Best</th><th class="num">Gap</th><th class="num">Average</th></tr></thead><tbody>${rows}</tbody></table></div>
          <dl class="dl"><dt>Theoretical best</dt><dd class="strong">${lap(d.theoretical)}</dd><dt>Best lap</dt><dd>${lap(d.best_lap)}</dd>
          <dt>Left on the table</dt><dd>${d.best_lap != null && d.theoretical != null ? (d.best_lap - d.theoretical).toFixed(3) + 's' : '–'}</dd>
          ${a?.deficit != null ? `<dt>To team ideal</dt><dd>${a.deficit < 0.0005 ? 'sets it' : '+' + a.deficit.toFixed(3) + 's'}</dd>` : ''}
          <dt>Clean laps</dt><dd>${d.clean_laps}</dd></dl></div></section>`;
      }
      const k = S.sector;
      const rank = sp.drivers.filter(x => x.best[k] != null).sort((p, q) => p.best[k] - q.best[k]);
      return `<section class="panel"><header class="panel-h"><div class="panel-t"><h2>Sector ${k + 1}</h2><p>Everyone’s best time through this sector</p></div></header>
        <div class="panel-b"><ol class="hlist">${rank.map((x, i) => `<li><button type="button" data-act="driver" data-v="${esc(x.driver)}" aria-pressed="${x.driver === d.driver}">
          <span class="hnum">${i + 1}</span><span class="hb"><span class="hrow"><span>${dtag(first(x.driver), x.ai)}</span><span>${s3(x.best[k])}</span></span>
          <span class="hwho">${i === 0 ? 'fastest' : '+' + (x.best[k] - rank[0].best[k]).toFixed(3) + 's'}</span></span></button></li>`).join('')}</ol>
        <p style="margin:14px 0 0"><button type="button" class="linkbtn" data-act="sector-all">← All sectors</button></p></div></section>`;
    }
    const L = layer();
    let body;
    if (L?.top.length) {
      body = `<ol class="hlist">${L.top.map((h, i) => `<li><button type="button" data-act="hot" data-v="${i}" aria-pressed="${S.focus === i}">
        <span class="hnum">${i + 1}</span><span class="hb"><span class="hrow"><span>${esc(where(h.pct))}</span><span>${h.count}</span></span>
        <span class="hwho">${esc(h.drivers.map(x => `${first(x.name)}${x.ai ? ' (AI)' : ''} ${x.n}`).join(' · '))}</span></span></button></li>`).join('')}</ol>`;
    } else if (L) body = '<p class="muted">No clusters: incidents here were spread around the lap.</p>';
    else body = '<p class="muted">Harvest the race replay to see where cars left the track or spun, and the corners that bite.</p>';
    if (L) body += '<p class="note">Found in the replay: cars leaving the circuit and spins. Contact that doesn’t end in either can’t be seen, so these undercount the official incident points.</p>';
    return `<section class="panel"><header class="panel-h"><div class="panel-t"><h2>Hotspots</h2><p>${L ? 'Clusters of incidents around the lap, worst first' : 'Appears once the replay is harvested'}</p></div></header><div class="panel-b">${body}</div></section>`;
  }

  function splitPanels() {
    const sp = split();
    if (!sp) return '';
    const d = spDriver(sp), mg = maxGap(sp);
    const head = sp.sectors.map((_, k) => `<th class="c"><button type="button" class="chip" style="padding:2px 7px" data-act="sector-h" data-v="${k}" aria-pressed="${S.view === 'sectors' && S.sector === k}">S${k + 1}</button></th>`).join('');
    const rows = sp.drivers.map(x => `<tr class="click${S.view === 'sectors' && x.driver === d.driver ? ' sel' : ''}" data-act="row" data-v="${esc(x.driver)}">
      <td class="name">${dtag(x.driver, x.ai)}</td>
      ${x.best.map((b, k) => {
        const g = gapOf(sp, b, k), st = g == null ? 0 : 1 + Math.round(Math.min(1, g / mg) * 6);
        return `<td class="heat h${st}"${tipAttr({ title: `${x.driver} · S${k + 1}`, lines: [{ value: s3(b), label: 'best' }, { value: gapText(g), label: 'to the team’s best' }, { value: s3(x.avg[k]), label: 'average' }] })}>${s3(b)}</td>`;
      }).join('')}
      <td class="num strong">${lap(x.theoretical)}</td><td class="num dim">${lap(x.best_lap)}</td></tr>`).join('');
    const ins = sp.analysis.insights.map(i => `<li class="${i.driver ? '' : 'team'}">${i.driver ? `<span style="margin-top:.15em">${markerIcon(slotOf(i.driver))}</span>` : '<span class="bullet"></span>'}<span>${esc(i.text)}</span></li>`).join('');
    return `<div class="g-3-2 g">
      <section class="panel"><header class="panel-h"><div class="panel-t"><h2>Sector times${D.splits.length > 1 ? ` · R${sp.round}` : ''}</h2><p>Best time per sector, shaded by the gap to the team’s best. Pick a row or a sector.</p></div></header>
        <div class="panel-b"><div class="scroll"><table class="tbl compact"><thead><tr><th>Driver</th>${head}<th class="num">Theoretical</th><th class="num">Best lap</th></tr></thead><tbody>${rows}</tbody></table></div>
        <p class="note">Sectors are iRacing’s official split lines, timed from the replay. Averages use clean laps within 107% of each driver’s best; pit laps are left out.</p></div></section>
      <section class="panel"><header class="panel-h"><div class="panel-t"><h2>Splits analysis</h2><p>Where the time is won and lost</p></div></header><div class="panel-b"><ul class="insights">${ins}</ul></div></section>
    </div>`;
  }

  // ---------------------------------------------------------------- render + events
  let mapEl = null, lastW = 0;
  function drawMap() {
    if (!mapEl) return;
    const w = mapEl.clientWidth;
    if (!w) return;
    lastW = w;
    mapEl.innerHTML = mapSvg(w);
  }
  function render() {
    root.innerHTML = `<div class="g-2-1 g" style="margin-top:var(--gap)">${mainPanel()}${sidePanel()}</div>${splitPanels()}`;
    root.prepend(dataEl);
    mapEl = root.querySelector('[data-map]');
    drawMap();
  }
  new ResizeObserver(() => { if (mapEl && mapEl.clientWidth !== lastW) drawMap(); }).observe(root);

  function act(t) {
    const a = t.dataset.act, v = t.dataset.v;
    switch (a) {
      case 'view': S.view = v; break;
      case 'scope': S.scope = v; S.focus = null; break;
      case 'who': S.who = v; S.focus = null; S.highlight = null; break;
      case 'hl': S.highlight = S.highlight === v ? null : v; break;
      case 'hot': S.focus = S.focus === +v ? null : +v; break;
      case 'split': S.split = +v; S.sector = null; break;
      case 'driver': S.driver = v; break;
      case 'row': S.driver = v; S.view = 'sectors'; break;
      case 'sector': S.sector = S.sector === +v ? null : +v; break;
      case 'sector-h': S.view = 'sectors'; S.sector = S.sector === +v ? null : +v; break;
      case 'sector-all': S.sector = null; break;
      default: return;
    }
    window.PitWall.tip.hide();
    render();
  }
  root.addEventListener('click', e => { const t = e.target.closest('[data-act]'); if (t && root.contains(t)) act(t); });
  root.addEventListener('keydown', e => {
    const t = e.target.closest('[data-act]');
    if (t && (e.key === 'Enter' || e.key === ' ') && t.tagName !== 'BUTTON') { e.preventDefault(); act(t); }
  });
  render();
})();
