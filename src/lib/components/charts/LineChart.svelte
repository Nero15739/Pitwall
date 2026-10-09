<script lang="ts" module>
  export interface LineSeries { name: string; slot: number | null; values: (number | null)[] }

  /** Round step for ~count ticks: 1, 2, 2.5, 5 × 10^n. */
  export function niceScale(lo: number, hi: number, count = 4) {
    if (!(hi > lo)) hi = lo + 1;
    const raw = (hi - lo) / count;
    const p = 10 ** Math.floor(Math.log10(raw));
    const f = raw / p;
    const step = (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p;
    const a = Math.floor(lo / step) * step, b = Math.ceil(hi / step) * step;
    const ticks: number[] = [];
    for (let v = a; v <= b + step / 1e6; v += step) ticks.push(+v.toFixed(10));
    return { lo: a, hi: b, ticks };
  }
</script>

<script lang="ts">
  import { SvelteSet } from 'svelte/reactivity';
  import { shapeOf, shapePath, slotColor } from '$lib/drivers';
  import Marker from '../Marker.svelte';

  /* Multi-series line chart: 2px lines, ≥8px shape markers with a surface ring, a crosshair
     that snaps to the nearest round, one tooltip listing every series, a toggle legend and a
     table view. Driver identity is colour + shape + name, never colour alone. */
  let {
    labels, titles = labels, series, height = 280, yTitle = '', fmt = (v: number) => String(v),
    zero = true, sortDesc = true, ariaLabel,
  }: {
    labels: string[]; titles?: string[]; series: LineSeries[]; height?: number; yTitle?: string;
    fmt?: (v: number) => string; zero?: boolean; sortDesc?: boolean; ariaLabel: string;
  } = $props();

  const M = { t: 18, r: 14, b: 26, l: 40 };
  let width = $state(0);
  const hidden = new SvelteSet<string>();
  let focus = $state<string | null>(null);
  let active = $state<number | null>(null);

  const n = $derived(labels.length);
  const visible = $derived(series.filter(s => !hidden.has(s.name)));
  const scale = $derived.by(() => {
    const vals = visible.flatMap(s => s.values).filter((v): v is number => v != null);
    if (!vals.length) return niceScale(0, 1);
    return niceScale(zero ? Math.min(0, ...vals) : Math.min(...vals), Math.max(...vals));
  });
  const iw = $derived(Math.max(0, width - M.l - M.r));
  const ih = $derived(height - M.t - M.b);
  const x = (i: number) => M.l + (iw * (i + 0.5)) / n;
  const y = (v: number) => M.t + ih * (1 - (v - scale.lo) / (scale.hi - scale.lo));
  const every = $derived(Math.max(1, Math.ceil((n * 64) / Math.max(iw, 1))));

  const line = (s: LineSeries) =>
    s.values.map((v, i) => (v == null ? null : `${x(i).toFixed(1)},${y(v).toFixed(1)}`)).filter(Boolean).map((p, k) => (k ? 'L' : 'M') + p).join('');

  const tipRows = $derived(
    active == null ? [] : visible
      .filter(s => s.values[active!] != null)
      .map(s => ({ s, v: s.values[active!] as number }))
      .sort((a, b) => (sortDesc ? b.v - a.v : a.v - b.v)),
  );
  let tipW = $state(0);
  const tipLeft = $derived(active == null ? 0 : x(active) + 12 + tipW > width ? x(active) - 12 - tipW : x(active) + 12);

  function pick(e: PointerEvent) {
    const r = (e.currentTarget as SVGRectElement).getBoundingClientRect();
    active = Math.max(0, Math.min(n - 1, Math.floor(((e.clientX - r.left) / r.width) * n)));
  }
  function key(e: KeyboardEvent) {
    if (e.key === 'ArrowRight') active = active == null ? 0 : Math.min(n - 1, active + 1);
    else if (e.key === 'ArrowLeft') active = active == null ? n - 1 : Math.max(0, active - 1);
    else if (e.key === 'Escape') active = null;
    else return;
    e.preventDefault();
  }
  const toggle = (name: string) => (hidden.has(name) ? hidden.delete(name) : hidden.add(name));
</script>

<div class="flex flex-col gap-3">
  <div class="flex flex-wrap gap-1" role="group" aria-label="Series">
    {#each series as s (s.name)}
      <button
        type="button"
        aria-pressed={!hidden.has(s.name)}
        class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition-opacity hover:bg-muted aria-[pressed=false]:opacity-45"
        onclick={() => toggle(s.name)}
        onpointerenter={() => (focus = s.name)}
        onpointerleave={() => (focus = null)}
        onfocus={() => (focus = s.name)}
        onblur={() => (focus = null)}
      >
        <span class="h-0.5 w-3 rounded-full" style="background:{slotColor(s.slot)}"></span>
        <Marker slot={s.slot} />
        {s.name}
      </button>
    {/each}
  </div>

  <!-- a keyboard-operable chart widget: arrows move the crosshair, Escape clears it -->
  <!-- svelte-ignore a11y_no_noninteractive_tabindex, a11y_no_noninteractive_element_interactions -->
  <div class="relative rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none" bind:clientWidth={width} tabindex="0"
    role="application" aria-roledescription="chart" aria-label="{ariaLabel}. Use the left and right arrow keys to read each round."
    onkeydown={key} onblur={() => (active = null)}>
    {#if width}
      <svg {width} {height} role="img" aria-label={ariaLabel} class="block overflow-visible">
        {#if yTitle}<text x={M.l - 8} y={8} class="fill-ink-muted text-[11px]" text-anchor="end">{yTitle}</text>{/if}
        {#each scale.ticks as t (t)}
          <line x1={M.l} x2={width - M.r} y1={y(t)} y2={y(t)} class="stroke-grid" stroke-width="1" />
          <text x={M.l - 8} y={y(t)} dy="0.32em" text-anchor="end" class="tnum fill-ink-muted text-[11px]">{fmt(t)}</text>
        {/each}
        {#each labels as l, i (i)}
          {#if i % every === 0}
            <text x={x(i)} y={height - 6} text-anchor="middle" class="fill-ink-muted text-[11px]">{l}</text>
          {/if}
        {/each}
        {#if active != null}
          <line x1={x(active)} x2={x(active)} y1={M.t} y2={M.t + ih} class="stroke-axis" stroke-width="1" />
        {/if}
        {#each visible as s (s.name)}
          {@const c = slotColor(s.slot)}
          {@const sh = shapePath(shapeOf(s.slot), 4.2)}
          <g opacity={focus && focus !== s.name ? 0.18 : 1} class="transition-opacity">
            <path d={line(s)} fill="none" stroke={c} stroke-width={focus === s.name ? 3 : 2} stroke-linejoin="round" stroke-linecap="round" />
            {#each s.values as v, i (i)}
              {#if v != null}
                <g transform="translate({x(i)},{y(v)}) scale({active === i ? 1.25 : 1})">
                  {#if sh.stroke}
                    <path d={sh.d} stroke="var(--card)" stroke-width="5" stroke-linecap="round" fill="none" />
                    <path d={sh.d} stroke={c} stroke-width="2.4" stroke-linecap="round" fill="none" />
                  {:else}
                    <path d={sh.d} fill={c} stroke="var(--card)" stroke-width="2" paint-order="stroke" />
                  {/if}
                </g>
              {/if}
            {/each}
          </g>
        {/each}
        <rect x={M.l} y={M.t} width={iw} height={ih} fill="transparent" role="presentation"
          onpointermove={pick} onpointerdown={pick} onpointerleave={() => (active = null)} />
      </svg>

      {#if active != null && tipRows.length}
        <div bind:clientWidth={tipW} class="pointer-events-none absolute z-10 min-w-40 rounded-lg border bg-popover px-3 py-2 text-xs shadow-lg" style="left:{tipLeft}px; top:{M.t}px">
          <div class="mb-1 font-semibold">{titles[active]}</div>
          {#each tipRows as r (r.s.name)}
            <div class="flex items-center gap-2 py-px">
              <span class="h-0.5 w-3 shrink-0 rounded-full" style="background:{slotColor(r.s.slot)}"></span>
              <span class="tnum font-semibold">{fmt(r.v)}</span>
              <span class="truncate text-muted-foreground">{r.s.name}</span>
            </div>
          {/each}
        </div>
      {/if}
    {/if}
  </div>

  <details class="text-xs text-muted-foreground">
    <summary class="w-fit cursor-pointer select-none hover:text-foreground">View as table</summary>
    <div class="mt-2 overflow-x-auto">
      <table class="w-full text-[13px] text-foreground">
        <thead><tr class="border-b text-left text-muted-foreground"><th class="py-1.5 pr-3 font-medium">Driver</th>
          {#each labels as l, i (i)}<th class="py-1.5 pr-3 text-right font-medium">{l}</th>{/each}</tr></thead>
        <tbody>
          {#each series as s (s.name)}
            <tr class="border-b last:border-0"><td class="py-1.5 pr-3">{s.name}</td>
              {#each s.values as v, i (i)}<td class="tnum py-1.5 pr-3 text-right">{v == null ? '–' : fmt(v)}</td>{/each}</tr>
          {/each}
        </tbody>
      </table>
    </div>
  </details>
</div>
