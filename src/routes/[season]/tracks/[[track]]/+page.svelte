<script lang="ts">
  import { page } from '$app/state';
  import DriverTag from '$lib/components/DriverTag.svelte';
  import HeatCell from '$lib/components/HeatCell.svelte';
  import Marker from '$lib/components/Marker.svelte';
  import PageHead from '$lib/components/PageHead.svelte';
  import Panel from '$lib/components/Panel.svelte';
  import Segmented from '$lib/components/Segmented.svelte';
  import StatTile from '$lib/components/StatTile.svelte';
  import TrackMap, { type MapLayer } from '$lib/components/TrackMap.svelte';
  import TrackOutline from '$lib/components/TrackOutline.svelte';
  import { carShort, dateShort, firstName, fix, km, lap, ord, sgn, trackTiny } from '$lib/format';
  import type { HotspotSummary } from '$shared/types';

  let { data } = $props();
  const S = $derived(data.season);
  const pages = $derived(S.track_pages);
  // default: the most recent round's track
  const tp = $derived(
    pages.find(p => String(p.track_id) === page.params.track)
      ?? pages.find(p => p.track_id === S.rounds[S.rounds.length - 1].track_id)!,
  );
  const info = $derived(data.tracks?.tracks[String(tp.track_id)]);
  const rounds = $derived(S.rounds.filter(r => tp.rounds.includes(r.round)));
  const slotOf = (name: string) => S.drivers.find(d => d.name === name)?.color ?? null;

  // ---- map filters (reset when the track changes)
  type Who = 'team' | 'field';
  let who = $state<Who>('team');
  let scope = $state('season');
  let highlight = $state<string | null>(null);
  let focusHotspot = $state<number | null>(null);
  $effect(() => { void tp.track_id; scope = 'season'; highlight = null; focusHotspot = null; });

  const otherSeasons = $derived((info?.seasons ?? []).filter(s => s.slug !== S.slug));
  const scopes = $derived([
    ...(tp.harvested_rounds.length > 1 ? tp.harvested_rounds.map(r => ({ value: `r${r}`, label: `R${r}` })) : []),
    { value: 'season', label: tp.harvested_rounds.length > 1 ? 'Season' : 'This race' },
    ...(otherSeasons.length && info?.hotspots_all ? [{ value: 'all', label: 'All seasons' }] : []),
  ]);

  const layer = $derived.by((): (MapLayer & { n: number }) | null => {
    let sum: HotspotSummary | null | undefined;
    let events = tp.hotspots?.events ?? [];
    if (scope === 'all') { sum = info?.hotspots_all; events = info?.hotspots_all?.events ?? []; }
    else if (scope.startsWith('r')) { sum = tp.by_round[scope.slice(1)]; events = events.filter(e => e.round === +scope.slice(1)); }
    else sum = tp.hotspots;
    if (!sum) return null;
    const team = who === 'team';
    return {
      events: team ? events.filter(e => e.team) : events,
      density: team ? sum.density_team : sum.density_field,
      top: team ? sum.top_team : sum.top_field,
      n: team ? sum.n_team : sum.n_field,
    };
  });
  const lengthM = $derived(tp.hotspots?.length_m ?? info?.length_m ?? null);
  const markerDrivers = $derived([...new Set((layer?.events ?? []).filter(e => e.team).map(e => e.driver))].sort());
  const where = (pct: number) => `${(pct * 100).toFixed(0)}% of the lap${lengthM ? ` · ${km(pct * lengthM)}` : ''}`;

  // ---- sector splits
  type View = 'hotspots' | 'sectors';
  let view = $state<View>('hotspots');
  let splitPick = $state<number | null>(null);
  let driverPick = $state<string | null>(null);
  let sector = $state<number | null>(null);
  $effect(() => { void tp.track_id; view = 'hotspots'; splitPick = null; driverPick = null; sector = null; });

  const sp = $derived(tp.splits.find(s => s.round === splitPick) ?? tp.splits[tp.splits.length - 1] ?? null);
  const spDriver = $derived(sp ? (sp.drivers.find(d => d.driver === driverPick) ?? sp.drivers[0]) : null);
  const gapOf = (best: number | null, k: number) => (best != null && sp?.team_best[k] != null ? Math.max(0, best - sp.team_best[k]!) : null);
  // one scale for every driver, so switching drivers compares like with like
  const maxGap = $derived(sp ? Math.max(0.001, ...sp.drivers.filter(d => d.team).flatMap(d => d.best.map((b, k) => gapOf(b, k) ?? 0))) : 1);
  const stepOf = (g: number | null) => (g == null ? null : 2 + Math.round(Math.min(1, g / maxGap) * 5));
  const gapText = (g: number | null) => (g == null ? '–' : g < 0.0005 ? 'best' : `+${g.toFixed(3)}`);
  const sectorView = $derived(view === 'sectors' && sp && spDriver ? {
    starts: sp.sectors,
    steps: spDriver.best.map((b, k) => stepOf(gapOf(b, k))),
    labels: spDriver.best.map((b, k) => gapText(gapOf(b, k))),
    active: sector,
    onpick: (k: number) => (sector = sector === k ? null : k),
  } : null);
  const ranking = $derived(sp && sector != null
    ? sp.drivers.filter(d => d.best[sector!] != null).sort((a, b) => a.best[sector!]! - b.best[sector!]!)
    : []);
  const analysisOf = (name: string) => sp?.analysis.drivers.find(a => a.driver === name);
  const s3 = (x: number | null | undefined) => (x == null ? '–' : x.toFixed(3));
</script>

<PageHead title={tp.track} sub="{rounds.map(r => `Round ${r.round} · ${dateShort(r.date)}`).join('  ·  ')} · {tp.laps} team laps" />

<!-- track picker -->
<nav aria-label="Tracks" class="-mx-1 mb-5 flex gap-2 overflow-x-auto px-1 pb-1">
  {#each pages as p (p.track_id)}
    {@const m = data.tracks?.tracks[String(p.track_id)]?.map}
    <a href="/{S.slug}/tracks/{p.track_id}" aria-current={p.track_id === tp.track_id ? 'page' : undefined}
      class="flex shrink-0 items-center gap-2 rounded-lg border bg-card py-1.5 pr-3 pl-2 text-[13px] font-medium text-muted-foreground transition-colors hover:text-foreground aria-[current=page]:border-foreground aria-[current=page]:text-foreground">
      <TrackOutline map={m} class="h-6 w-9" />
      <span>R{p.rounds.join(', R')} {trackTiny(p.track_short)}</span>
    </a>
  {/each}
</nav>

<!-- incident overview -->
<div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
  <StatTile label="Team incidents here" value={tp.team_inc}
    meta="{fix(tp.team_inc_per_10, 1)} per 10 laps · season {fix(tp.season_team_inc_per_10, 1)}" />
  <StatTile label="Cleanest here" meta={tp.cleanest ? `${fix(tp.cleanest.inc_per_10, 1)} per 10 laps` : ''}>
    {#if tp.cleanest}<DriverTag name={tp.cleanest.driver} label={firstName(tp.cleanest.driver)} />{:else}–{/if}
  </StatTile>
  <StatTile label="Roughest here" meta={tp.roughest ? `${fix(tp.roughest.inc_per_10, 1)} per 10 laps` : ''}>
    {#if tp.roughest}<DriverTag name={tp.roughest.driver} label={firstName(tp.roughest.driver)} />{:else}–{/if}
  </StatTile>
  <StatTile label="Whole field" value={tp.field_inc} unit="incidents" meta="{tp.ai_inc} of them by AI drivers" />
  <StatTile label="Difficulty" value={tp.difficulty_rank ? `#${tp.difficulty_rank}` : '–'} unit="of {S.tracks.length}"
    meta="incidents, consistency and places lost" />
</div>

<div class="mt-3 grid gap-3 lg:grid-cols-3">
  <Panel class="lg:col-span-2" title={view === 'sectors' ? 'Sector splits' : 'Crash hotspots'}
    sub={view === 'sectors' && sp && spDriver
      ? `${spDriver.driver}${spDriver.ai ? ' (AI)' : ''} · best time in each sector against the team's best. Pick a sector to compare everyone.`
      : layer ? `${layer.n} off-tracks and spins found in the replay${who === 'team' ? ', team drivers' : ', whole field'}, stacked where they happened` : 'Where incidents happened on the lap, from the race replay'}>
    {#snippet actions()}
      {#if tp.splits.length}
        <Segmented label="Map view" bind:value={view} options={[{ value: 'hotspots', label: 'Hotspots' }, { value: 'sectors', label: 'Sectors' }]} />
      {/if}
      {#if view === 'hotspots' && layer}
        {#if scopes.length > 1}<Segmented label="Races" bind:value={scope} options={scopes} />{/if}
        <Segmented label="Drivers" bind:value={who} options={[{ value: 'team', label: 'Team' }, { value: 'field', label: 'Whole field' }]} />
      {:else if view === 'sectors' && tp.splits.length > 1}
        <Segmented label="Race" bind:value={() => String(sp?.round), v => (splitPick = +v)} options={tp.splits.map(s => ({ value: String(s.round), label: `R${s.round}` }))} />
      {/if}
    {/snippet}

    {#if info?.map}
      <TrackMap map={info.map} layer={view === 'hotspots' ? layer : null} sectors={sectorView} {lengthM} {highlight} {slotOf} {focusHotspot}
        title="{tp.track} map{view === 'sectors' ? ' with sector splits' : layer ? ' with incident hotspots' : ''}" />
    {:else}
      <div class="grid h-64 place-items-center rounded-lg bg-muted text-sm text-muted-foreground">Track map unavailable for this layout.</div>
    {/if}

    {#if view === 'sectors' && sp}
      <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground">
        <span class="flex items-center gap-1.5">Team best
          {#each [2, 3, 4, 5, 6, 7] as s (s)}<span class="h-2 w-3.5 first:rounded-l-sm last:rounded-r-sm" style="background:var(--seq-{s}00)"></span>{/each}
          +{maxGap.toFixed(3)}s</span>
        <span class="flex items-center gap-1.5"><span class="h-2.5 w-0.5 bg-foreground"></span>Sector line</span>
      </div>
      <div class="mt-3 flex flex-wrap items-center gap-1" role="group" aria-label="Driver">
        <span class="mr-1 text-xs text-muted-foreground">Driver</span>
        {#each sp.drivers as d (d.driver)}
          <button type="button" aria-pressed={spDriver?.driver === d.driver} onclick={() => (driverPick = d.driver)}
            class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs hover:bg-muted aria-pressed:border-foreground aria-pressed:bg-muted">
            <Marker slot={d.ai ? null : slotOf(d.driver)} />{firstName(d.driver)}{d.ai ? ' (AI)' : ''}
          </button>
        {/each}
      </div>
    {:else if layer}
      <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground">
        <span class="flex items-center gap-1.5">Fewer
          {#each [1, 2, 3, 4, 5, 6, 7] as s (s)}<span class="h-2 w-3.5 first:rounded-l-sm last:rounded-r-sm" style="background:var(--seq-{s}00)"></span>{/each}
          more incidents</span>
        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-foreground"></span>Off track</span>
        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full border-[1.5px] border-foreground"></span>Spin</span>
        {#if who === 'field'}<span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-ai"></span>AI driver</span>{/if}
        <span class="flex items-center gap-1.5">{#if info?.map?.sf}<span class="font-semibold text-foreground">▶</span>{/if}Start/finish</span>
      </div>
      {#if markerDrivers.length}
        <div class="mt-3 flex flex-wrap items-center gap-1" role="group" aria-label="Highlight a driver">
          <span class="mr-1 text-xs text-muted-foreground">Highlight</span>
          {#each markerDrivers as d (d)}
            <button type="button" aria-pressed={highlight === d} onclick={() => (highlight = highlight === d ? null : d)}
              class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs hover:bg-muted aria-pressed:border-foreground aria-pressed:bg-muted">
              <Marker slot={slotOf(d)} />{firstName(d)}
            </button>
          {/each}
        </div>
      {/if}
    {:else}
      <div class="mt-4 rounded-lg border border-dashed p-4 text-[13px]">
        <p class="font-medium">No crash locations or splits yet</p>
        <p class="mt-1 text-muted-foreground">
          Both come from the race replay. Open the replay in iRacing and run the harvester on the PC with iRacing:
        </p>
        <ul class="mt-2 grid gap-1">
          {#each tp.pending as p (p.subsession)}
            <li><code class="rounded bg-muted px-1.5 py-0.5 text-xs">npm run harvest -- --open {p.subsession}</code> <span class="text-muted-foreground">Round {p.round}</span></li>
          {/each}
        </ul>
      </div>
    {/if}
  </Panel>

  {#if view === 'sectors' && sp && spDriver}
    {@const a = analysisOf(spDriver.driver)}
    <Panel title={sector == null ? `${firstName(spDriver.driver)}'s splits` : `Sector ${sector + 1}`}
      sub={sector == null ? 'Best and average time in each sector' : 'Everyone\'s best time through this sector'}>
      {#if sector == null}
        <table class="tbl">
          <thead><tr><th>Sector</th><th class="num">Best</th><th class="num">Gap</th><th class="num">Average</th></tr></thead>
          <tbody>
            {#each spDriver.best as b, k (k)}
              {@const g = gapOf(b, k)}
              <tr class="cursor-pointer hover:bg-muted/60" onclick={() => (sector = k)}>
                <td>S{k + 1}{#if a?.weakest === k}<span class="ml-1.5 text-xs text-muted-foreground">weakest</span>{:else if a?.strongest === k}<span class="ml-1.5 text-xs text-muted-foreground">strongest</span>{/if}</td>
                <td class="num strong">{s3(b)}</td>
                <td class="num {g != null && g < 0.0005 ? 'text-good font-semibold' : 'dim'}">{gapText(g)}</td>
                <td class="num dim">{s3(spDriver.avg[k])}</td>
              </tr>
            {/each}
          </tbody>
        </table>
        <dl class="mt-4 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[13px]">
          <dt class="text-muted-foreground">Theoretical best</dt><dd class="tnum text-right font-semibold">{lap(spDriver.theoretical)}</dd>
          <dt class="text-muted-foreground">Best lap</dt><dd class="tnum text-right">{lap(spDriver.best_lap)}</dd>
          <dt class="text-muted-foreground">Left on the table</dt><dd class="tnum text-right">{spDriver.best_lap != null && spDriver.theoretical != null ? (spDriver.best_lap - spDriver.theoretical).toFixed(3) + 's' : '–'}</dd>
          {#if a?.deficit != null}<dt class="text-muted-foreground">To the team's ideal lap</dt><dd class="tnum text-right">{a.deficit < 0.0005 ? 'sets it' : '+' + a.deficit.toFixed(3) + 's'}</dd>{/if}
          <dt class="text-muted-foreground">Clean laps</dt><dd class="tnum text-right">{spDriver.clean_laps}</dd>
        </dl>
      {:else}
        <ol class="grid grid-cols-1 gap-1">
          {#each ranking as d, i (d.driver)}
            {@const g = d.best[sector]! - ranking[0].best[sector]!}
            <li>
              <button type="button" onclick={() => (driverPick = d.driver)} aria-pressed={spDriver.driver === d.driver}
                class="grid w-full grid-cols-[1.25rem_minmax(0,1fr)_auto_auto] items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] hover:bg-muted aria-pressed:bg-muted">
                <span class="tnum text-xs text-muted-foreground">{i + 1}</span>
                <DriverTag name={d.driver} ai={d.ai} label={firstName(d.driver)} />
                <span class="tnum font-semibold">{s3(d.best[sector])}</span>
                <span class="tnum w-14 text-right text-muted-foreground">{i === 0 ? '' : '+' + g.toFixed(3)}</span>
              </button>
            </li>
          {/each}
        </ol>
        <button type="button" class="mt-3 text-xs font-medium text-muted-foreground hover:text-foreground" onclick={() => (sector = null)}>← All sectors</button>
      {/if}
    </Panel>
  {:else}
    <Panel title="Hotspots" sub={layer ? 'Clusters of incidents around the lap, worst first' : 'Appears once the replay is harvested'}>
      {#if layer?.top.length}
        <ol class="grid grid-cols-1 gap-1">
          {#each layer.top as h, i (i)}
            <li>
              <button type="button" class="flex w-full gap-3 rounded-lg p-2 text-left hover:bg-muted aria-pressed:bg-muted" aria-pressed={focusHotspot === i}
                onclick={() => (focusHotspot = focusHotspot === i ? null : i)}>
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-foreground text-xs font-bold text-background">{i + 1}</span>
                <span class="min-w-0 flex-1">
                  <span class="flex items-baseline justify-between gap-2">
                    <span class="text-[13px] font-medium">{where(h.pct)}</span>
                    <span class="tnum text-[13px] font-semibold">{h.count}</span>
                  </span>
                  <span class="mt-0.5 block truncate text-xs text-muted-foreground">
                    {h.drivers.map(d => `${firstName(d.name)}${d.ai ? ' (AI)' : ''} ${d.n}`).join(' · ')}
                  </span>
                </span>
              </button>
            </li>
          {/each}
        </ol>
      {/if}
      {#if layer && !layer.top.length}
        <p class="text-[13px] text-muted-foreground">No clusters: incidents here were spread around the lap.</p>
      {/if}
      {#if layer}
        <p class="mt-3 text-xs text-muted-foreground">Found in the replay: cars leaving the circuit and spins. Contact that doesn't end in either can't be seen, so these undercount the official incident points.</p>
      {:else}
        <p class="text-[13px] text-muted-foreground">Harvest the race replay to see where cars left the track or spun, and the corners that bite.</p>
      {/if}
    </Panel>
  {/if}
</div>

{#if sp}
  <div class="mt-3 grid gap-3 lg:grid-cols-5">
    <Panel title="Sector times" sub="Best time per sector, shaded by the gap to the team's best. Pick a row or a sector." class="lg:col-span-3">
      <div class="scroll-x">
        <table class="tbl min-w-[560px]">
          <thead><tr>
            <th>Driver</th>
            {#each sp.sectors as _, k (k)}
              <th class="text-center"><button type="button" class="rounded px-1.5 hover:bg-muted aria-pressed:bg-foreground aria-pressed:text-background" aria-pressed={sector === k}
                onclick={() => { sector = sector === k ? null : k; view = 'sectors'; }}>S{k + 1}</button></th>
            {/each}
            <th class="num">Theoretical</th><th class="num">Best lap</th>
          </tr></thead>
          <tbody>
            {#each sp.drivers as d (d.driver)}
              <tr class="cursor-pointer {spDriver?.driver === d.driver ? 'bg-muted/70' : 'hover:bg-muted/40'}" onclick={() => { driverPick = d.driver; view = 'sectors'; }}>
                <td class="max-w-0 min-w-32"><DriverTag name={d.driver} ai={d.ai} /></td>
                {#each d.best as b, k (k)}
                  {@const g = gapOf(b, k)}
                  <HeatCell value={g} min={0} max={maxGap} text={s3(b)}
                    tipContent={{ title: `${d.driver} · S${k + 1}`, lines: [{ value: s3(b), label: 'best' }, { value: gapText(g), label: "to the team's best" }, { value: s3(d.avg[k]), label: 'average' }] }} />
                {/each}
                <td class="num strong">{lap(d.theoretical)}</td>
                <td class="num dim">{lap(d.best_lap)}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
      <p class="mt-2 text-xs text-muted-foreground">
        Sectors are iRacing's official split lines, timed from the replay. Averages use clean laps within 107% of each driver's best; pit laps are left out.
      </p>
    </Panel>

    <Panel title="Splits analysis" sub="Where the time is won and lost" class="lg:col-span-2">
      <ul class="grid gap-3 text-[13px]">
        {#each sp.analysis.insights as ins, i (i)}
          <li class="flex gap-2.5">
            {#if ins.driver}
              <span class="mt-0.5"><Marker slot={slotOf(ins.driver)} /></span>
            {:else}
              <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-muted-foreground"></span>
            {/if}
            <span class={ins.driver ? '' : 'font-medium'}>{ins.text}</span>
          </li>
        {/each}
      </ul>
    </Panel>
  </div>
{/if}

<Panel title="Drivers at {trackTiny(tp.track_short)}" sub="Incidents per 10 laps compared with each driver's season rate" class="mt-3">
  <div class="overflow-x-auto">
    <table class="w-full min-w-[640px] text-[13px]">
      <thead><tr class="border-b text-left text-xs text-muted-foreground">
        <th class="py-2 pr-3 font-medium">Driver</th>
        {#if rounds.length > 1}<th class="py-2 pr-3 font-medium">Rnd</th>{/if}
        <th class="py-2 pr-3 font-medium">Car</th>
        <th class="py-2 pr-3 text-right font-medium">Grid → finish</th>
        <th class="py-2 pr-3 text-right font-medium">Laps</th>
        <th class="py-2 pr-3 text-right font-medium">Incidents</th>
        <th class="py-2 pr-3 text-right font-medium">Per 10 laps</th>
        <th class="py-2 pr-3 text-right font-medium">vs season</th>
        <th class="py-2 text-right font-medium" title="Off-tracks and spins found in the replay">Off / spins</th>
      </tr></thead>
      <tbody>
        {#each tp.drivers as d (d.driver + d.round)}
          {@const delta = d.inc_per_10 != null && d.season_inc_per_10 != null ? d.inc_per_10 - d.season_inc_per_10 : null}
          <tr class="border-b last:border-0">
            <td class="py-2 pr-3"><DriverTag name={d.driver} /></td>
            {#if rounds.length > 1}<td class="tnum py-2 pr-3">R{d.round}</td>{/if}
            <td class="py-2 pr-3 text-muted-foreground">{carShort(d.car)}</td>
            <td class="tnum py-2 pr-3 text-right">{ord(d.start)} → {ord(d.finish)}</td>
            <td class="tnum py-2 pr-3 text-right">{d.laps}</td>
            <td class="tnum py-2 pr-3 text-right font-semibold">{d.inc}</td>
            <td class="tnum py-2 pr-3 text-right">{fix(d.inc_per_10, 1)}</td>
            <td class="tnum py-2 pr-3 text-right {delta == null ? '' : delta > 0 ? 'text-foreground' : 'text-good'}">{delta == null ? '–' : sgn(delta, 1)}</td>
            <td class="tnum py-2 text-right text-muted-foreground">{d.markers ?? '–'}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
</Panel>

<div class="mt-3 grid gap-3 sm:grid-cols-2">
  {#each rounds as r (r.round)}
    <Panel title="Round {r.round} result" sub="{dateShort(r.date)} · {r.humans} drivers + {r.ai} AI · {r.laps} laps · SoF {r.sof ?? '–'}">
      <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[13px]">
        <dt class="text-muted-foreground">Winner</dt><dd class="min-w-0 text-right"><DriverTag name={r.winner} ai={r.winner_ai} /></dd>
        <dt class="text-muted-foreground">Pole</dt><dd class="min-w-0 text-right"><DriverTag name={r.pole} ai={r.pole_ai} /></dd>
        <dt class="text-muted-foreground">Fastest lap</dt><dd class="truncate text-right"><span class="tnum">{lap(r.fastest?.time)}</span> <span class="text-muted-foreground">{r.fastest?.name}</span></dd>
        <dt class="text-muted-foreground">Team fastest</dt><dd class="truncate text-right"><span class="tnum">{lap(r.team_fastest?.time)}</span> <span class="text-muted-foreground">{r.team_fastest?.name}</span></dd>
        <dt class="text-muted-foreground">Cautions</dt><dd class="tnum text-right">{r.cautions}</dd>
        <dt class="text-muted-foreground">Field incidents</dt><dd class="tnum text-right">{r.field_inc}</dd>
      </dl>
    </Panel>
  {/each}
</div>
