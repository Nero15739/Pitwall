/* Track page: the circuit from iRacing's official map with a crash heat map (incident density
   painted along the racing line, and a circle on every spot cars went off, sized and coloured by
   how many), for the team, the whole field or one driver, plus a sector view of the splits.
   Lap distance → SVG point: path.getPointAtLength(L * ((1 + offset + dir * pct) % 1)). */
(() => {
  'use strict';
  const root = document.getElementById('track-app');
  const dataEl = document.getElementById('track-data');
  if (!root || !dataEl || !window.PitWall) return;
  const D = JSON.parse(dataEl.textContent);
  const { esc, markerIcon } = window.PitWall;

  const slotOf = n => (Object.prototype.hasOwnProperty.call(D.slots, n) ? D.slots[n] : null);
  const first = n => String(n).split(' ')[0];
  const s3 = x => (x == null ? '–' : x.toFixed(3));
  const lap = t => { if (t == null) return '–'; const m = Math.floor(t / 60); return `${m}:${(t - m * 60).toFixed(3).padStart(6, '0')}`; };
  const km = m => (m == null ? null : m >= 1000 ? `${(m / 1000).toFixed(2)} km` : `${Math.round(m)} m`);
  const where = pct => `${(pct * 100).toFixed(0)}% of the lap${D.lengthM ? ' · ' + km(pct * D.lengthM) : ''}`;
  const plural = (n, one, many = one + 's') => `${n} ${n === 1 ? one : many}`;
  // a live log from the Pit Wall Logger (every incident iRacing counted) rather than a replay harvest
  const LIVE = Boolean(D.live);
  const tipAttr = c => ` data-tip="${esc(JSON.stringify(c))}"`;
  const dtag = (name, ai) => `<span class="dtag">${markerIcon(ai ? null : slotOf(name))}<span class="dtag-n">${esc(name)}</span>${ai ? '<span class="ai-badge">AI</span>' : ''}</span>`;
  const seg = (act, opts, value, label) => `<div class="seg" role="group" aria-label="${esc(label)}">${opts.map(([v, t]) => `<button type="button" data-act="${act}" data-v="${esc(v)}" aria-pressed="${String(v) === String(value)}">${esc(t)}</button>`).join('')}</div>`;

  // sel: 'team', 'field', or 'd:<driver name>'. focus: lap position of the picked hotspot.
  const S = { view: 'hotspots', scope: 'season', sel: 'team', focus: null, split: null, driver: null, sector: null };
  const scopes = [
    ...(D.harvested.length > 1 ? D.harvested.map(r => [`r${r}`, `R${r}`]) : []),
    ['season', D.harvested.length > 1 ? 'Season' : 'This race'],
    ...(D.all ? [['all', 'All seasons']] : []),
  ];

  // ---------------------------------------------------------------- incidents for the current state
  function scopeEvents() {
    if (!D.events) return null;
    if (S.scope === 'all') return D.all ?? D.events;
    return S.scope.startsWith('r') ? D.events.filter(e => e.round === +S.scope.slice(1)) : D.events;
  }
  const selName = () => (S.sel.startsWith('d:') ? S.sel.slice(2) : null);
  function selected() {
    const evs = scopeEvents();
    if (!evs) return null;
    if (S.sel === 'field') return evs;
    const who = selName();
    return who == null ? evs.filter(e => e.team) : evs.filter(e => e.driver === who);
  }
  const split = () => D.splits.find(s => s.round === S.split) ?? D.splits[D.splits.length - 1] ?? null;
  const spDriver = sp => (sp ? sp.drivers.find(d => d.driver === S.driver) ?? sp.drivers[0] : null);
  const gapOf = (sp, best, k) => (best != null && sp.team_best[k] != null ? Math.max(0, best - sp.team_best[k]) : null);
  const maxGap = sp => Math.max(0.001, ...sp.drivers.filter(d => d.team).flatMap(d => d.best.map((b, k) => gapOf(sp, b, k) ?? 0)));
  const stepOf = (g, mg) => (g == null ? null : 2 + Math.round(Math.min(1, g / mg) * 5)); // the best sector still reads as coloured
  const gapText = g => (g == null ? '–' : g < 0.0005 ? 'best' : `+${g.toFixed(3)}`);

  // ---------------------------------------------------------------- heat: density and hotspots round the lap
  // The lap is a circle: everything wraps at the start/finish line.
  const BINS = 200, SIGMA = 1.5;
  const KERNEL = (() => {
    const r = Math.ceil(SIGMA * 3);
    const k = Array.from({ length: 2 * r + 1 }, (_, i) => Math.exp(-((i - r) ** 2) / (2 * SIGMA ** 2)));
    const s = k.reduce((a, b) => a + b, 0);
    return k.map(v => v / s);
  })();
  const wrapBin = i => ((i % BINS) + BINS) % BINS;
  const binOf = pct => Math.min(BINS - 1, Math.floor((((pct % 1) + 1) % 1) * BINS));
  const lapDist = (a, b) => { const d = Math.abs(a - b) % 1; return Math.min(d, 1 - d); };
  function density(evs) {
    const raw = new Array(BINS).fill(0);
    for (const e of evs) raw[binOf(e.pct)]++;
    const r = (KERNEL.length - 1) / 2;
    return raw.map((_, i) => KERNEL.reduce((acc, k, j) => acc + k * raw[wrapBin(i + j - r)], 0));
  }
  function spot(evs) {
    const s = evs.reduce((a, e) => a + Math.sin(2 * Math.PI * e.pct), 0), c = evs.reduce((a, e) => a + Math.cos(2 * Math.PI * e.pct), 0);
    const tally = new Map();
    for (const e of evs) {
      const t = tally.get(e.driver) ?? { name: e.driver, n: 0, ai: e.ai };
      t.n++;
      tally.set(e.driver, t);
    }
    return {
      pct: (((Math.atan2(s, c) / (2 * Math.PI)) % 1) + 1) % 1, n: evs.length, evs,
      spins: evs.filter(e => e.kind === 'spin').length,
      drivers: [...tally.values()].sort((a, b) => b.n - a.n || a.name.localeCompare(b.name)),
    };
  }
  /** Incidents gathered round the peaks of their density, with peaks at least sepBins apart. */
  function cluster(evs, sepBins) {
    if (!evs.length) return [];
    const d = density(evs);
    const peaks = [];
    d.map((v, i) => ({ v, i })).filter(({ v, i }) => v > 0 && v >= d[wrapBin(i - 1)] && v > d[wrapBin(i + 1)]).sort((a, b) => b.v - a.v)
      .forEach(c => { if (peaks.every(p => Math.min(Math.abs(p - c.i), BINS - Math.abs(p - c.i)) >= sepBins)) peaks.push(c.i); });
    const groups = peaks.map(i => ({ c: (i + 0.5) / BINS, evs: [] }));
    for (const e of evs) groups.reduce((b, g) => (lapDist(e.pct, g.c) < lapDist(e.pct, b.c) ? g : b)).evs.push(e);
    return groups.filter(g => g.evs.length).map(g => spot(g.evs));
  }
  /** Hotspots sized for the screen: circles that would run into their neighbour merge with it. */
  function layout(evs, width) {
    const u = geo ? geo.box.w / width : 1, lapPx = geo ? geo.L / u : 1400;
    const rMin = 7, rMax = Math.max(17, Math.min(30, width * 0.055));
    let spots = cluster(evs, Math.max(3, Math.ceil((rMax * 1.1) / (lapPx / BINS))));
    const size = () => {
      const top = Math.max(8, ...spots.map(b => b.n));
      for (const b of spots) b.r = rMin + (rMax - rMin) * Math.sqrt((b.n - 1) / (top - 1));
    };
    for (let guard = 0; geo && guard < 60; guard++) {
      size();
      spots.sort((a, b) => a.pct - b.pct);
      const i = spots.findIndex((a, k) => {
        const b = spots[(k + 1) % spots.length];
        if (a === b) return false;
        const pa = pointAt(a.pct), pb = pointAt(b.pct);
        return Math.hypot(pa.x - pb.x, pa.y - pb.y) / u < (a.r + b.r) * 0.92;
      });
      if (i < 0) break;
      const j = (i + 1) % spots.length, both = spot([...spots[i].evs, ...spots[j].evs]);
      spots = spots.filter((_, k) => k !== i && k !== j).concat(both);
    }
    size();
    const most = Math.max(1, ...spots.map(b => b.n));
    for (const b of spots) b.step = most > 1 ? 2 + Math.round(((b.n - 1) / (most - 1)) * 5) : 2;
    return spots.sort((a, b) => b.n - a.n || a.pct - b.pct);
  }
  const isFocus = b => S.focus != null && lapDist(b.pct, S.focus) < 1e-6;
  const mix = b => {
    if (LIVE) {
      const k = kind => b.evs.filter(e => e.kind === kind).length;
      return [[k('off track'), 'off-track'], [k('spin / wall'), 'spin or wall', 'spins or walls'], [k('car contact'), 'contact', 'contacts']]
        .filter(([n]) => n).map(([n, one, many]) => plural(n, one, many)).join(' · ');
    }
    return b.spins === 0 ? plural(b.n, 'off-track') : b.spins === b.n ? plural(b.n, 'spin') : `${b.n - b.spins} off-track · ${plural(b.spins, 'spin')}`;
  };
  const lapsOf = b => [...new Set(b.evs.map(e => e.lap))].sort((x, y) => x - y);
  const whoLine = b => (selName() != null
    ? `${lapsOf(b).length === 1 ? 'Lap' : 'Laps'} ${lapsOf(b).join(', ')}`
    : b.drivers.map(x => `${first(x.name)}${x.ai ? ' (AI)' : ''} ${x.n}`).join(' · '));

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
    const pad = Math.max(b.width, b.height) * 0.1;
    geo = { pts, N, L, outward: area > 0 ? -1 : 1, box: { x: b.x - pad, y: b.y - pad, w: b.width + 2 * pad, h: b.height + 2 * pad } };
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
  let SPOTS = [];   // the hotspots on screen, hottest first
  function mapSvg(width) {
    const g = measure(), { box } = g, u = box.w / width, T = 11;
    const evs = S.view === 'hotspots' ? selected() : null;
    const sp = S.view === 'sectors' ? split() : null;
    const out = [`<svg viewBox="${box.x} ${box.y} ${box.w} ${box.h}" role="img" aria-label="${esc(D.track)} map" style="aspect-ratio:${box.w} / ${box.h}">`];
    out.push(`<defs><filter id="heat-glow" x="-40%" y="-40%" width="180%" height="180%"><feGaussianBlur stdDeviation="${(7 * u).toFixed(2)}"/></filter></defs>`);
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
    } else if (evs?.length) {
      // the racing line takes the colour of how often cars went off there
      const den = density(evs), maxD = Math.max(...den), per = g.N / BINS;
      den.forEach((d, i) => {
        if (d < maxD * 0.04) return;
        const pts = g.pts.slice(Math.floor(i * per), Math.floor((i + 1) * per) + 1);
        out.push(`<path d="M${pts.map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join('L')}" fill="none" stroke="var(--h${1 + Math.round((d / maxD) * 6)})" stroke-width="${T}" vector-effect="non-scaling-stroke" stroke-linecap="butt"/>`);
      });
    }

    if (D.map.sf) out.push(`<path d="${esc(D.map.sf)}" fill="var(--ink)"/>`);
    else {
      const p = pointAt(0), r = (T / 2 + 6) * u;
      out.push(`<line x1="${p.x - p.nx * r}" y1="${p.y - p.ny * r}" x2="${p.x + p.nx * r}" y2="${p.y + p.ny * r}" stroke="var(--ink)" stroke-width="4" vector-effect="non-scaling-stroke"/>`);
    }
    out.push(labels);

    if (evs) {
      const at = SPOTS.map(b => ({ b, p: pointAt(b.pct) }));
      // a soft glow under the circles, then the circles themselves, biggest first so small ones stay on top
      out.push(`<g filter="url(#heat-glow)" opacity=".5" pointer-events="none">${at.map(({ b, p }) => `<circle cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="${(b.r * 1.5 * u).toFixed(1)}" fill="var(--h${b.step})"/>`).join('')}</g>`);
      [...at].sort((x, y) => y.b.r - x.b.r).forEach(({ b, p }) => {
        const on = isFocus(b), dim = S.focus != null && !on;
        const tip = { title: `${plural(b.n, 'incident')} · ${where(b.pct)}`, lines: [
          ...(selName() != null ? [{ value: whoLine(b) }] : b.drivers.slice(0, 5).map(x => ({ value: String(x.n), label: `${x.name}${x.ai ? ' (AI)' : ''}` }))),
          ...(selName() == null && b.drivers.length > 5 ? [{ value: `+${b.drivers.length - 5}`, label: 'more drivers' }] : []),
          { value: mix(b) }] };
        out.push(`<g class="hot" transform="translate(${p.x.toFixed(1)},${p.y.toFixed(1)})" role="button" tabindex="0" data-act="hot" data-v="${b.pct}" aria-pressed="${on}" aria-label="${esc(`${plural(b.n, 'incident')} at ${where(b.pct)}`)}" opacity="${dim ? 0.45 : 1}"${tipAttr(tip)}>
          ${on ? `<circle r="${((b.r + 6.5) * u).toFixed(1)}" fill="var(--accent)" stroke="var(--ink)" stroke-width="${(2 * u).toFixed(2)}"/>` : ''}
          <circle r="${(b.r * u).toFixed(1)}" fill="var(--h${b.step})" fill-opacity=".9" stroke="var(--ink)" stroke-width="${((on ? 2.6 : 1.8) * u).toFixed(2)}"/>
          ${b.r >= 9 ? `<text text-anchor="middle" dy="0.36em" font-size="${(Math.min(15, 8 + b.r * 0.34) * u).toFixed(1)}" font-weight="800" font-family="var(--mono)" fill="var(--hi${b.step})">${b.n}</text>` : ''}</g>`);
      });
    }
    out.push('</svg>');
    return out.join('');
  }

  // ---------------------------------------------------------------- panels
  function mainPanel() {
    const evs = S.view === 'hotspots' ? selected() : null, sp = split(), d = spDriver(sp);
    const sectors = S.view === 'sectors' && sp;
    const who = selName();
    const title = sectors ? 'Sector splits' : 'Crash heat map';
    const sub = sectors ? `${d.driver}${d.ai ? ' (AI)' : ''} · best time in each sector against the team’s best. Pick a sector to compare everyone.`
      : evs && LIVE ? `${plural(evs.length, 'incident')} logged live for ${who ?? (S.sel === 'field' ? 'every car' : 'the human drivers')}. Each circle sits where they happened: the bigger and hotter, the more often.`
      : evs ? `${plural(evs.length, 'off-track or spin', 'off-tracks and spins')} found in the replay for ${who ?? (S.sel === 'field' ? 'the whole field' : 'the team')}. Each circle sits where they happened: the bigger and hotter, the more often.`
      : 'Where incidents happened on the lap, from the race replay';
    let actions = '';
    if (D.splits.length) actions += seg('view', [['hotspots', 'Heat map'], ['sectors', 'Sectors']], S.view, 'Map view');
    if (!sectors && evs) {
      if (scopes.length > 1) actions += seg('scope', scopes, S.scope, 'Races');
    } else if (sectors && D.splits.length > 1) {
      actions += seg('split', D.splits.map(s => [s.round, `R${s.round}`]), sp.round, 'Race');
    }

    let under = '';
    if (sectors) {
      const mg = maxGap(sp);
      under = `<div class="map-legend"><span>Team best <span class="ramp">${[2, 3, 4, 5, 6, 7].map(s => `<i style="background:var(--h${s})"></i>`).join('')}</span> +${mg.toFixed(3)}s</span>
        <span><i style="width:3px;height:14px;background:var(--ink);display:inline-block"></i> Sector line</span></div>
        <div class="chips map-controls" role="group" aria-label="Driver"><span class="chips-l">Driver</span>${sp.drivers.map(x => `<button type="button" class="chip" data-act="driver" data-v="${esc(x.driver)}" aria-pressed="${x.driver === d.driver}">${markerIcon(x.ai ? null : slotOf(x.driver))}${esc(first(x.driver))}${x.ai ? ' (AI)' : ''}</button>`).join('')}</div>`;
    } else if (evs) {
      // one chip per view of the heat map: the team, the whole field, then each team driver
      const all = scopeEvents(), count = new Map();
      for (const e of all) if (e.team) count.set(e.driver, (count.get(e.driver) ?? 0) + 1);
      const names = [...new Set(D.events.filter(e => e.team).map(e => e.driver))].sort();
      const chip = (v, label, n, icon = '') => `<button type="button" class="chip heat-chip" data-act="sel" data-v="${esc(v)}" aria-pressed="${S.sel === v}">${icon}${esc(label)}<b>${n}</b></button>`;
      under = `<div class="chips map-controls" role="group" aria-label="Whose incidents to show"><span class="chips-l">Show</span>
          ${chip('team', LIVE ? 'Humans' : 'Team', all.filter(e => e.team).length)}${chip('field', 'Whole field', all.length)}
          ${names.map(n => chip(`d:${n}`, first(n), count.get(n) ?? 0, markerIcon(slotOf(n)))).join('')}</div>
        <div class="map-legend"><span>Fewer <span class="ramp">${[2, 3, 4, 5, 6, 7].map(s => `<i style="background:var(--h${s})"></i>`).join('')}</span> more incidents</span>
          <span><span class="bub s"></span><span class="bub l"></span>Circle size: incidents at that spot</span>
          <span><i style="width:3px;height:14px;background:var(--ink);display:inline-block"></i> Start/finish</span></div>`;
    } else {
      under = `<div class="pending"><strong>No crash locations or splits yet.</strong>
        <p class="muted" style="margin:6px 0 10px">Both come from the race replay. On the PC with iRacing, open the replay and run the harvester; with an API key set up it uploads here by itself.</p>
        ${D.pending.map(p => `<div style="margin-top:6px"><code>npm run harvest -- --open ${p.subsession}</code> <span class="dim">Round ${p.round}</span></div>`).join('')}</div>`;
    }
    const map = D.map ? `<div class="map" data-map style="max-width:${Math.round((640 * measure().box.w) / measure().box.h)}px"></div>`
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
    const evs = selected(), who = selName();
    const top = SPOTS.filter(b => b.n > 1).slice(0, 6);
    let body;
    if (top.length) {
      body = `<ol class="hlist">${top.map(b => `<li><button type="button" data-act="hot" data-v="${b.pct}" aria-pressed="${isFocus(b)}">
        <span class="hnum heat h${b.step}">${b.n}</span><span class="hb"><span class="hrow"><span>${esc(where(b.pct))}</span></span>
        <span class="hwho">${esc(whoLine(b))}</span><span class="hwho">${esc(mix(b))}</span></span></button></li>`).join('')}</ol>`;
    } else if (evs?.length) body = `<p class="muted">No repeat offences: ${who ? esc(first(who)) + '’s' : 'the'} ${plural(evs.length, 'incident')} ${evs.length === 1 ? 'was a one-off' : 'were spread around the lap'}.</p>`;
    else if (evs) body = `<p class="muted">${who ? esc(first(who)) + ' kept it on the black stuff here: nothing ' + (LIVE ? 'logged.' : 'in the replay.') : (LIVE ? 'Nothing logged: a clean session.' : 'Nothing in the replay: a clean race.')}</p>`;
    else body = '<p class="muted">Harvest the race replay to see where cars left the track or spun, and the corners that bite.</p>';
    if (evs) body += LIVE
      ? '<p class="note">Logged live from iRacing’s own incident counts, so every incident is here, contact included. A circle sits where the car left the track or, when that didn’t register (tarmac run-off), where it was about 0.8 s before its count rose.</p>'
      : '<p class="note">Found in the replay: cars leaving the circuit and spins. Contact that doesn’t end in either can’t be seen, so these undercount the official incident points.</p>';
    const head = who ? `${esc(first(who))}’s trouble spots` : 'Hotspots';
    const sub = !evs ? 'Appears once the replay is harvested' : who ? `Where this driver ${LIVE ? 'had more than one incident' : 'went off more than once'}`
      : `Where ${S.sel === 'field' ? 'the field' : LIVE ? 'the humans' : 'the team'} ${LIVE ? 'had most incidents' : 'went off most'}, worst first`;
    return `<section class="panel"><header class="panel-h"><div class="panel-t"><h2>${head}</h2><p>${sub}</p></div></header><div class="panel-b">${body}</div></section>`;
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
  // The hotspots depend on the map's size on screen (circles mustn't collide), so the map is drawn
  // once its width is known, and the list beside it is filled from what the map shows.
  let mapEl = null, sideEl = null, lastW = 0;
  function drawMap() {
    const w = mapEl?.clientWidth || lastW || 640;
    lastW = mapEl?.clientWidth || lastW;
    const evs = S.view === 'hotspots' ? selected() : null;
    SPOTS = evs ? layout(evs, w) : [];
    if (S.focus != null && !SPOTS.some(isFocus)) S.focus = null;
    if (mapEl && mapEl.clientWidth) mapEl.innerHTML = mapSvg(w);
    if (sideEl) sideEl.innerHTML = sidePanel();
  }
  function render() {
    root.innerHTML = `<div class="g-2-1 g" style="margin-top:var(--gap)">${mainPanel()}<div data-side></div></div>${splitPanels()}`;
    root.prepend(dataEl);
    mapEl = root.querySelector('[data-map]');
    sideEl = root.querySelector('[data-side]');
    drawMap();
    window.PitWall.edges?.();
  }
  new ResizeObserver(() => { if (mapEl && mapEl.clientWidth !== lastW) drawMap(); }).observe(root);

  function act(t) {
    const a = t.dataset.act, v = t.dataset.v;
    switch (a) {
      case 'view': S.view = v; break;
      case 'scope': S.scope = v; S.focus = null; break;
      case 'sel': S.sel = v; S.focus = null; break;
      case 'hot': S.focus = S.focus != null && lapDist(S.focus, +v) < 1e-6 ? null : +v; break;
      case 'split': S.split = +v; S.sector = null; break;
      case 'driver': S.driver = v; break;
      case 'row': S.driver = v; S.view = 'sectors'; break;
      case 'sector': S.sector = S.sector === +v ? null : +v; break;
      case 'sector-h': S.view = 'sectors'; S.sector = S.sector === +v ? null : +v; break;
      case 'sector-all': S.sector = null; break;
      default: return;
    }
    window.PitWall.tip.hide();
    // picking a hotspot only restyles the map and the list: keep the page where it is
    if (a === 'hot') drawMap(); else render();
  }
  root.addEventListener('click', e => { const t = e.target.closest('[data-act]'); if (t && root.contains(t)) act(t); });
  root.addEventListener('keydown', e => {
    const t = e.target.closest('[data-act]');
    if (t && (e.key === 'Enter' || e.key === ' ') && t.tagName !== 'BUTTON') { e.preventDefault(); act(t); }
  });
  render();
})();
