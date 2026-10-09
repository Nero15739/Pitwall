<script lang="ts" module>
  import type { Hotspot, IncidentEvent, TrackMap } from '$shared/types';

  export interface MapLayer {
    events: IncidentEvent[];
    density: number[];      // HOTSPOT_BINS bins around the lap
    top: Hotspot[];
  }

  /** Sector view: each sector shaded on the sequential ramp, labelled and clickable. */
  export interface SectorView {
    starts: number[];               // lap distance where each sector begins (first is 0)
    steps: (number | null)[];       // ramp step 1..7 per sector (null = no time)
    labels: string[];               // short text per sector, e.g. "+0.361"
    active: number | null;
    onpick: (k: number) => void;
  }
</script>

<script lang="ts">
  import { shapeOf, shapePath, slotColor } from '$lib/drivers';
  import { km } from '$lib/format';
  import { tip } from '$lib/tip.svelte';

  /* The circuit from iRacing's official map, with incident density painted along the racing
     line and every replay incident marker stacked outward from the track like a pin.
     Lap distance → SVG: point = path.getPointAtLength(L * ((1 + offset + direction * pct) % 1)). */
  let { map, layer, sectors = null, lengthM = null, highlight = null, slotOf, focusHotspot = null, title }: {
    map: TrackMap;
    layer: MapLayer | null;
    sectors?: SectorView | null;
    lengthM?: number | null;
    highlight?: string | null;
    slotOf: (driver: string) => number | null;
    focusHotspot?: number | null;
    title: string;
  } = $props();

  const SAMPLES = 800;
  let pathEl = $state<SVGPathElement>();
  let width = $state(0);
  let samples = $state<{ x: number; y: number }[]>([]);
  let box = $state({ x: 0, y: 0, w: 1920, h: 1080 });
  let outward = $state(1); // which side of the line is "outside" the loop

  // sample the lap once per map: SAMPLES points from the start/finish line in driving direction
  $effect(() => {
    const el = pathEl;
    if (!el || !map) return;
    const L = el.getTotalLength();
    const at = (pct: number) => el.getPointAtLength(L * ((((1 + map.offset + map.direction * pct) % 1) + 1) % 1));
    const pts = Array.from({ length: SAMPLES + 1 }, (_, k) => { const p = at(k / SAMPLES); return { x: p.x, y: p.y }; });
    // shoelace sign: tells us which perpendicular points away from the infield
    let area = 0;
    for (let k = 0; k < SAMPLES; k++) area += pts[k].x * pts[k + 1].y - pts[k + 1].x * pts[k].y;
    outward = area > 0 ? -1 : 1;
    const b = el.getBBox();
    const pad = Math.max(b.width, b.height) * 0.07;
    box = { x: b.x - pad, y: b.y - pad, w: b.width + 2 * pad, h: b.height + 2 * pad };
    samples = pts;
  });

  const u = $derived(width ? box.w / width : 1); // SVG units per CSS pixel
  const TRACK_PX = 9;

  function pointAt(pct: number) {
    const f = ((pct % 1) + 1) % 1 * SAMPLES;
    const k = Math.floor(f), t = f - k;
    const a = samples[k], b = samples[Math.min(SAMPLES, k + 1)];
    const c = samples[Math.max(0, k - 2)], d = samples[Math.min(SAMPLES, k + 2)];
    const dx = d.x - c.x, dy = d.y - c.y, len = Math.hypot(dx, dy) || 1;
    return { x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t, nx: (-dy / len) * outward, ny: (dx / len) * outward };
  }

  const maxD = $derived(layer ? Math.max(...layer.density, 0) : 0);
  const heat = $derived.by(() => {
    if (!layer || !samples.length || maxD <= 0) return [];
    const per = SAMPLES / layer.density.length;
    return layer.density.flatMap((d, i) => {
      if (d < maxD * 0.04) return [];
      const pts = samples.slice(Math.floor(i * per), Math.floor((i + 1) * per) + 1);
      return [{ d: 'M' + pts.map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join('L'), step: 1 + Math.round((d / maxD) * 6) }];
    });
  });

  // pins: sorted round the lap; markers within ~0.5% of a lap stack outward
  const pins = $derived.by(() => {
    if (!layer || !samples.length) return [];
    const evs = [...layer.events].sort((a, b) => a.pct - b.pct);
    const out: { e: IncidentEvent; x: number; y: number; hx: number; hy: number }[] = [];
    let anchor = -1, depth = 0;
    for (const e of evs) {
      if (anchor >= 0 && e.pct - anchor < 0.005) depth++; else { anchor = e.pct; depth = 0; }
      const p = pointAt(e.pct);
      const off = (TRACK_PX / 2 + 6 + depth * 7) * u;
      out.push({ e, x: p.x + p.nx * off, y: p.y + p.ny * off, hx: p.x, hy: p.y });
    }
    return out;
  });

  const badges = $derived(layer && samples.length ? layer.top.map((h, i) => {
    const p = pointAt(h.pct);
    const off = -(TRACK_PX / 2 + 16) * u; // inside the loop, clear of the pins
    return { n: i + 1, h, x: p.x + p.nx * off, y: p.y + p.ny * off };
  }) : []);

  const seg = (from: number, to: number) =>
    'M' + samples.slice(Math.floor(from * SAMPLES), Math.ceil(to * SAMPLES) + 1).map(p => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join('L');

  const sectorShapes = $derived.by(() => {
    if (!sectors || !samples.length) return [];
    const s = sectors.starts;
    return s.map((from, k) => {
      const to = k + 1 < s.length ? s[k + 1] : 1;
      const mid = pointAt((from + to) / 2);
      const off = -(TRACK_PX / 2 + 22) * u; // labels sit inside the loop
      const tick = pointAt(from), r = (TRACK_PX / 2 + 4) * u;
      return {
        k, d: seg(from, to), step: sectors.steps[k],
        lx: mid.x + mid.nx * off, ly: mid.y + mid.ny * off,
        tick: { x1: tick.x - tick.nx * r, y1: tick.y - tick.ny * r, x2: tick.x + tick.nx * r, y2: tick.y + tick.ny * r },
      };
    });
  });
  const pickKey = (e: KeyboardEvent, k: number) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sectors?.onpick(k); }
  };

  const sfTick = $derived.by(() => {
    if (!samples.length) return null;
    const p = pointAt(0), r = (TRACK_PX / 2 + 5) * u;
    return { x1: p.x - p.nx * r, y1: p.y - p.ny * r, x2: p.x + p.nx * r, y2: p.y + p.ny * r };
  });

  const where = (pct: number) => `${(pct * 100).toFixed(0)}% of the lap${lengthM ? ` · ${km(pct * lengthM)}` : ''}`;
  const pinTip = (e: IncidentEvent) => ({
    title: `${e.driver}${e.ai ? ' (AI)' : ''}`,
    lines: [
      { value: e.kind === 'spin' ? 'Spun' : 'Off track', label: e.kind === 'spin' ? (e.off ? 'and left the circuit' : 'rolled backwards') : 'left the circuit' },
      { value: `Lap ${e.lap}`, label: `Round ${e.round}${e.season ? ' · ' + e.season : ''}` },
      { value: where(e.pct) },
    ],
  });
</script>

<!-- width capped so the map is never taller than ~600px -->
<div bind:clientWidth={width} class="relative mx-auto w-full" style="max-width:min(100%, {Math.round((600 * box.w) / box.h)}px)">
  <svg viewBox="{box.x} {box.y} {box.w} {box.h}" class="block h-auto w-full" style="aspect-ratio:{box.w} / {box.h}" role="img" aria-label={title}>
    <!-- racing line: an outline in the axis tone, so it reads on both themes -->
    <path bind:this={pathEl} d={map.path} fill="none" stroke="var(--axis)" stroke-width={TRACK_PX + 3} vector-effect="non-scaling-stroke" stroke-linejoin="round" />
    <path d={map.path} fill="none" stroke="var(--muted)" stroke-width={TRACK_PX} vector-effect="non-scaling-stroke" stroke-linejoin="round" />
    {#if sectors}
      {#each sectorShapes as s (s.k)}
        {#if sectors.active === s.k}
          <path d={s.d} fill="none" stroke="var(--foreground)" stroke-width={TRACK_PX + 7} vector-effect="non-scaling-stroke" stroke-linecap="butt" />
        {/if}
        <path d={s.d} fill="none" stroke={s.step ? `var(--seq-${s.step}00)` : 'var(--muted)'} stroke-width={TRACK_PX} vector-effect="non-scaling-stroke"
          stroke-linecap="butt" class="cursor-pointer" role="presentation" onclick={() => sectors.onpick(s.k)} />
      {/each}
      {#each sectorShapes as s (s.k)}
        <line {...s.tick} stroke="var(--foreground)" stroke-width="2" vector-effect="non-scaling-stroke" />
      {/each}
    {:else}
      {#each heat as h, i (i)}
        <path d={h.d} fill="none" stroke="var(--seq-{h.step}00)" stroke-width={TRACK_PX} vector-effect="non-scaling-stroke" stroke-linecap="butt" />
      {/each}
    {/if}

    {#if map.sf}
      <path d={map.sf} fill="var(--foreground)" opacity="0.85" />
    {:else if sfTick}
      <line {...sfTick} stroke="var(--foreground)" stroke-width="3" vector-effect="non-scaling-stroke" />
    {/if}

    {#if sectors}
      {#each sectorShapes as s (s.k)}
        {@const on = sectors.active === s.k}
        <g transform="translate({s.lx},{s.ly})" role="button" tabindex="0" class="cursor-pointer outline-none"
          aria-label="Sector {s.k + 1}: {sectors.labels[s.k]}" aria-pressed={on}
          onclick={() => sectors.onpick(s.k)} onkeydown={e => pickKey(e, s.k)}>
          <rect x={-30 * u} y={-12 * u} width={60 * u} height={24 * u} rx={7 * u}
            fill={on ? 'var(--foreground)' : 'var(--card)'} stroke="var(--border)" stroke-width={1 * u} />
          <text text-anchor="middle" dy="0.35em" font-size={11 * u} font-weight="600" fill={on ? 'var(--background)' : 'var(--foreground)'}>
            S{s.k + 1} {sectors.labels[s.k]}
          </text>
        </g>
      {/each}
    {/if}

    {#each sectors ? [] : pins as p, i (i)}
      {@const hi = highlight === p.e.driver}
      {@const dim = highlight != null && !hi}
      {@const slot = hi ? slotOf(p.e.driver) : null}
      <g opacity={dim ? 0.22 : 1} use:tip={pinTip(p.e)} role="presentation">
        <line x1={p.hx} y1={p.hy} x2={p.x} y2={p.y} stroke="var(--axis)" stroke-width="1" vector-effect="non-scaling-stroke" opacity="0.6" />
        <circle cx={p.x} cy={p.y} r={12 * u} fill="transparent" />
        {#if hi}
          {@const sh = shapePath(shapeOf(slot), 4.6 * u)}
          <g transform="translate({p.x},{p.y})">
            {#if sh.stroke}
              <path d={sh.d} stroke="var(--card)" stroke-width={5 * u} stroke-linecap="round" fill="none" />
              <path d={sh.d} stroke={slotColor(slot)} stroke-width={2.4 * u} stroke-linecap="round" fill="none" />
            {:else}
              <path d={sh.d} fill={slotColor(slot)} stroke="var(--card)" stroke-width={2 * u} paint-order="stroke" />
            {/if}
          </g>
        {:else if p.e.kind === 'spin'}
          <!-- spins are rings, off-tracks are dots -->
          <circle cx={p.x} cy={p.y} r={3.4 * u} fill="var(--card)" stroke={p.e.ai ? 'var(--ai)' : 'var(--foreground)'} stroke-width={1.8 * u} />
        {:else}
          <circle cx={p.x} cy={p.y} r={3.6 * u} fill={p.e.ai ? 'var(--ai)' : 'var(--foreground)'} stroke="var(--card)" stroke-width={1.6 * u} paint-order="stroke" />
        {/if}
      </g>
    {/each}

    {#each sectors ? [] : badges as b (b.n)}
      <g transform="translate({b.x},{b.y})">
        {#if focusHotspot === b.n - 1}
          <circle r={15 * u} fill="none" stroke="var(--foreground)" stroke-width={2 * u} />
        {/if}
        <circle r={10 * u} fill="var(--foreground)" />
        <text text-anchor="middle" dy="0.35em" fill="var(--background)" font-size={12 * u} font-weight="700">{b.n}</text>
      </g>
    {/each}
  </svg>
</div>
