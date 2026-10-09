<script lang="ts">
  import BarList from '$lib/components/charts/BarList.svelte';
  import LineChart from '$lib/components/charts/LineChart.svelte';
  import DriverTag from '$lib/components/DriverTag.svelte';
  import HeatCell from '$lib/components/HeatCell.svelte';
  import PageHead from '$lib/components/PageHead.svelte';
  import Panel from '$lib/components/Panel.svelte';
  import Segmented from '$lib/components/Segmented.svelte';
  import StatTile from '$lib/components/StatTile.svelte';
  import { slotColor } from '$lib/drivers';
  import { firstName, fix, ord, pct, per10, sgn, trackTiny } from '$lib/format';

  let { data } = $props();
  const S = $derived(data.season);
  const R = $derived(S.rounds);
  const names = $derived(S.standings.map(s => s.driver));
  const res = (n: string, r: number) => S.results.find(x => x.driver === n && x.round === r);
  const trackOf = (r: number) => R.find(x => x.round === r)!;

  const byRate = $derived([...S.standings].filter(s => s.inc_per_lap != null).sort((a, b) => a.inc_per_lap! - b.inc_per_lap!));
  const tracks = $derived([...S.tracks].sort((a, b) => a.difficulty_rank - b.difficulty_rank));
  const teamInc = $derived(R.reduce((a, r) => a + r.team_inc, 0));

  type Mode = 'race' | 'cum' | 'rate';
  let mode = $state<Mode>('race');
  const series = $derived(names.map(n => {
    let tot = 0;
    return {
      name: n, slot: S.drivers.find(d => d.name === n)?.color ?? null,
      values: R.map(r => {
        const x = res(n, r.round);
        if (!x) return mode === 'cum' ? tot : null;
        if (mode === 'cum') return (tot += x.inc);
        if (mode === 'rate') return x.inc_per_lap != null ? +(x.inc_per_lap * 10).toFixed(2) : null;
        return x.inc;
      }),
    };
  }));

  const ipl = $derived(S.results.map(x => x.inc_per_lap).filter((v): v is number => v != null));
</script>

<PageHead title="Incidents" sub="Incident points per driver, which tracks bit the hardest, and for whom. Rates are per 10 laps so short and long races compare fairly." />

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
  <StatTile label="Team incidents" value={teamInc} meta="{fix(teamInc / R.length, 1)} per round across the team" />
  <StatTile label="Cleanest driver" meta="{per10(byRate[0]?.inc_per_lap)} incidents per 10 laps">
    <DriverTag name={byRate[0].driver} label={firstName(byRate[0].driver)} />
  </StatTile>
  <StatTile label="Most incident-prone" meta="{per10(byRate[byRate.length - 1]?.inc_per_lap)} incidents per 10 laps">
    <DriverTag name={byRate[byRate.length - 1].driver} label={firstName(byRate[byRate.length - 1].driver)} />
  </StatTile>
  <StatTile label="Hardest track for the group" meta="{per10(tracks[0].inc_per_lap)} per 10 laps · {tracks[0].inc} team incidents">
    <a href="/{S.slug}/tracks/{trackOf(tracks[0].round).track_id}" class="truncate text-xl underline-offset-4 hover:underline sm:text-2xl">{trackTiny(tracks[0].track_short)}</a>
  </StatTile>
</div>

<Panel class="mt-3" title="Incident points by driver"
  sub={{ race: 'Incident points in each round', cum: 'Running total of incident points across the season', rate: 'Incidents per 10 laps completed in each round' }[mode]}>
  {#snippet actions()}
    <Segmented label="Measure" bind:value={mode} options={[{ value: 'race', label: 'Per race' }, { value: 'cum', label: 'Cumulative' }, { value: 'rate', label: 'Per 10 laps' }]} />
  {/snippet}
  <LineChart labels={R.map(r => `R${r.round} ${trackTiny(r.track_short)}`)} titles={R.map(r => r.track)} {series}
    yTitle={mode === 'rate' ? 'Per 10 laps' : 'Incidents'} fmt={v => (mode === 'rate' ? v.toFixed(1) : String(Math.round(v)))} height={300}
    ariaLabel="Incident points by driver and round" />
</Panel>

<div class="mt-3 grid gap-3 lg:grid-cols-3">
  <Panel title="Track difficulty" sub="Team incidents per 10 laps at each track">
    <BarList label="Incidents per 10 laps by track" color="var(--s0)" bars={[...S.tracks].sort((a, b) => (b.inc_per_lap ?? 0) - (a.inc_per_lap ?? 0)).map(t => ({
      key: String(t.round), label: trackTiny(t.track_short), value: (t.inc_per_lap ?? 0) * 10, text: per10(t.inc_per_lap),
      tip: { title: t.track, lines: [{ value: `${t.inc}`, label: 'team incidents' }, { value: fix(t.avg_inc, 1), label: 'per driver' }] },
    }))} />
  </Panel>
  <Panel title="Difficulty ranking" sub="Blends incident rate (50%), lap consistency (30%) and places lost from the grid (20%)" class="lg:col-span-2">
    <div class="scroll-x">
      <table class="tbl min-w-[520px]">
        <thead><tr><th>#</th><th>Track</th><th class="num">Inc / 10 laps</th><th class="num">Consistency</th><th class="num">Avg places</th><th class="num">Score</th></tr></thead>
        <tbody>
          {#each tracks as t (t.round)}
            <tr>
              <td class="num dim">{t.difficulty_rank}</td>
              <td><a class="hover:underline" href="/{S.slug}/tracks/{trackOf(t.round).track_id}">{t.track_short}</a></td>
              <td class="num">{per10(t.inc_per_lap)}</td><td class="num">{pct(t.avg_consistency_pct)}</td>
              <td class="num">{sgn(t.avg_gained, 1)}</td><td class="num strong">{sgn(t.difficulty, 2)}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  </Panel>
</div>

<Panel class="mt-3" title="Incidents by driver and track" sub="Incident count, shaded by rate per lap. The stronger the shading, the rougher the night.">
  <div class="scroll-x">
    <table class="tbl min-w-[560px]">
      <thead><tr><th>Driver</th>{#each R as r (r.round)}<th class="text-center">R{r.round} {trackTiny(r.track_short)}</th>{/each}<th class="num">Total</th><th class="num">Per race</th></tr></thead>
      <tbody>
        {#each names as n (n)}
          {@const s = S.standings.find(s => s.driver === n)!}
          <tr>
            <td class="max-w-0 min-w-32"><DriverTag name={n} /></td>
            {#each R as r (r.round)}
              {@const x = res(n, r.round)}
              <HeatCell value={x?.inc_per_lap ?? null} min={Math.min(...ipl)} max={Math.max(...ipl)} text={x ? String(x.inc) : '–'}
                tipContent={x ? { title: `${n} · ${r.track_short}`, lines: [{ value: `${x.inc}`, label: `incidents in ${x.laps} laps` }, { value: per10(x.inc_per_lap), label: 'per 10 laps' }, { value: ord(x.finish), label: 'finish' }] } : null} />
            {/each}
            <td class="num strong">{s.inc}</td><td class="num">{fix(s.inc_per_race, 1)}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
</Panel>

<h2 class="mt-8 mb-3 text-[15px] font-semibold tracking-tight">Each driver's toughest round</h2>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
  {#each names as n (n)}
    {@const h = S.hardest_by_driver[n]}
    {#if h}
      <div class="rounded-xl border bg-card p-4" style="border-top:3px solid {slotColor(S.drivers.find(d => d.name === n)?.color)}">
        <DriverTag name={n} />
        <dl class="mt-3 grid gap-2 text-[13px]">
          <div><dt class="text-xs text-muted-foreground">Worst incident rate</dt>
            <dd><span class="font-medium">{trackOf(h.most_incidents.round).track_short}</span> · {h.most_incidents.inc}x ({per10(h.most_incidents.inc_per_lap)} / 10 laps)</dd></div>
          <div><dt class="text-xs text-muted-foreground">Furthest off the pace</dt>
            <dd><span class="font-medium">{trackOf(h.slowest_vs_team.round).track_short}</span> · +{fix(h.slowest_vs_team.gap_pct, 2)}%</dd></div>
          <div><dt class="text-xs text-muted-foreground">Best result</dt>
            <dd><span class="font-medium">{trackOf(h.best_track.round).track_short}</span> · {ord(h.best_track.finish)}</dd></div>
        </dl>
      </div>
    {/if}
  {/each}
</div>
