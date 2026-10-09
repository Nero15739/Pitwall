<script lang="ts">
  import BarList from '$lib/components/charts/BarList.svelte';
  import LineChart from '$lib/components/charts/LineChart.svelte';
  import DriverTag from '$lib/components/DriverTag.svelte';
  import HeatCell from '$lib/components/HeatCell.svelte';
  import PageHead from '$lib/components/PageHead.svelte';
  import Panel from '$lib/components/Panel.svelte';
  import Segmented from '$lib/components/Segmented.svelte';
  import { carShort, fix, lap, ord, pct, trackTiny } from '$lib/format';

  let { data } = $props();
  const S = $derived(data.season);
  const R = $derived(S.rounds);
  const names = $derived(S.standings.map(s => s.driver));
  const res = (n: string, r: number) => S.results.find(x => x.driver === n && x.round === r);

  let pick = $state('');
  const sel = $derived(+(pick || R[R.length - 1].round));
  const roundOpts = $derived(R.map(r => ({ value: String(r.round), label: `R${r.round} ${trackTiny(r.track_short)}` })));

  const gaps = $derived(S.results.filter(x => x.round === sel && x.best != null).sort((a, b) => a.best! - b.best!));
  const all = $derived(S.results.filter(x => x.round === sel).sort((a, b) => a.finish - b.finish));
  const bestOf = (k: 'prac' | 'qual' | 'best') => Math.min(...all.map(x => x[k] ?? Infinity));

  const heat = $derived(names.map(n => R.map(r => res(n, r.round)?.gap_team_pct ?? null)));
  const flat = $derived(heat.flat().filter((v): v is number => v != null));

  const consistency = $derived([...S.standings].filter(s => s.avg_consistency_pct != null).sort((a, b) => a.avg_consistency_pct! - b.avg_consistency_pct!));

  // season average gap to the team's best in each session (relative %)
  const sessions = $derived(names.map(n => {
    const g = (k: 'prac' | 'qual' | 'best') => {
      const vals = R.map(r => {
        const xs = S.results.filter(x => x.round === r.round && x[k] != null);
        const me = xs.find(x => x.driver === n);
        if (!me) return null;
        return (me[k]! / Math.min(...xs.map(x => x[k]!)) - 1) * 100;
      }).filter((v): v is number => v != null);
      return vals.length ? vals.reduce((a, b) => a + b, 0) / vals.length : null;
    };
    const p = g('prac'), q = g('qual'), r = g('best');
    const trend = r != null && p != null ? (r < p - 0.1 ? 'Finds pace on race day' : r > p + 0.1 ? 'Quicker in practice' : 'Steady across sessions') : '';
    return { n, p, q, r, trend };
  }));
</script>

<PageHead title="Lap times" sub="Who was quickest, by how much, and how consistent. Gaps are measured to the team's fastest lap at each round." />

<Panel title="Fastest laps by round" sub="Team fastest race lap, the margin to the next team-mate, and the fastest AI lap as a benchmark">
  <div class="scroll-x">
    <table class="tbl min-w-[720px]">
      <thead><tr><th>Rnd</th><th>Track</th><th>Team fastest</th><th class="num">Lap</th><th>Car</th><th class="num">Margin</th><th class="num">Fastest AI</th><th class="num">Team vs AI</th></tr></thead>
      <tbody>
        {#each R as r (r.round)}
          {@const tv = S.tracks.find(t => t.round === r.round)?.team_vs_ai_pct}
          <tr>
            <td class="num dim">{r.round}</td>
            <td><a class="hover:underline" href="/{S.slug}/tracks/{r.track_id}">{r.track_short}</a></td>
            <td>{#if r.team_fastest}<DriverTag name={r.team_fastest.name} />{:else}–{/if}</td>
            <td class="num strong">{lap(r.team_fastest?.time)}</td>
            <td class="dim">{carShort(r.team_fastest?.car)}</td>
            <td class="num">{r.team_fastest?.margin != null ? r.team_fastest.margin.toFixed(3) + 's' : '–'}</td>
            <td class="num dim">{lap(r.ai_best)}</td>
            <td class="num">{tv == null ? '–' : tv < 0 ? `${Math.abs(tv).toFixed(2)}% faster` : `${tv.toFixed(2)}% slower`}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
</Panel>

<Panel title="Round detail" sub="Best race lap per driver, as a gap to the team's fastest" class="mt-3">
  {#snippet actions()}<Segmented label="Round" bind:value={() => String(sel), v => (pick = v)} options={roundOpts} />{/snippet}
  <div class="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
    <BarList label="Gap to team fastest lap" bars={gaps.map(x => ({
      key: x.driver, label: x.driver, driver: true, slot: S.drivers.find(d => d.name === x.driver)?.color ?? null,
      value: x.gap_team_s ?? 0, text: x.gap_team_s ? `+${x.gap_team_s.toFixed(3)}s` : 'fastest',
      tip: { title: x.driver, lines: [{ value: lap(x.best), label: `lap ${x.best_lap_num}` }, { value: carShort(x.car) }] },
    }))} />
    <div class="scroll-x">
      <table class="tbl min-w-[560px]">
        <thead><tr><th>Driver</th><th class="num">Practice</th><th class="num">Qualifying</th><th class="num">Race best</th><th class="num">Race avg</th><th class="num">Finish</th></tr></thead>
        <tbody>
          {#each all as x (x.driver)}
            <tr>
              <td><DriverTag name={x.driver} /></td>
              <td class="num {x.prac === bestOf('prac') ? 'strong' : ''}">{lap(x.prac)}</td>
              <td class="num {x.qual === bestOf('qual') ? 'strong' : ''}">{lap(x.qual)}{#if x.qual_rank}<span class="dim"> · P{x.qual_rank}</span>{/if}</td>
              <td class="num {x.best === bestOf('best') ? 'strong' : ''}">{lap(x.best)}</td>
              <td class="num dim">{lap(x.avg)}</td>
              <td class="num">{ord(x.finish)}</td>
            </tr>
          {/each}
        </tbody>
      </table>
      <p class="mt-2 text-xs text-muted-foreground">Bold marks the team's quickest in each session. Qualifying position is overall, including AI.</p>
    </div>
  </div>
</Panel>

<div class="mt-3 grid gap-3 lg:grid-cols-2">
  <Panel title="Pace gap heatmap" sub="Best lap gap to the team's fastest, in %. The stronger the shading, the further off the pace.">
    <div class="scroll-x">
      <table class="tbl">
        <thead><tr><th>Driver</th>{#each R as r (r.round)}<th class="text-center" title={r.track}>R{r.round}</th>{/each}<th class="num">Avg</th></tr></thead>
        <tbody>
          {#each names as n, i (n)}
            {@const s = S.standings.find(s => s.driver === n)}
            <tr>
              <td class="max-w-0 min-w-32"><DriverTag name={n} /></td>
              {#each heat[i] as v, j (j)}
                {@const x = res(n, R[j].round)}
                <HeatCell value={v} min={Math.min(...flat)} max={Math.max(...flat)} text={v == null ? '–' : v.toFixed(2)}
                  tipContent={x ? { title: `${n} · ${R[j].track_short}`, lines: [{ value: lap(x.best), label: carShort(x.car) }, { value: v == null ? 'no lap' : `+${v.toFixed(2)}%`, label: 'to team fastest' }] } : null} />
              {/each}
              <td class="num strong">{fix(s?.avg_gap_team_pct, 2)}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  </Panel>
  <Panel title="Pace trend" sub="Gap to team fastest (%) each round. Lower is faster.">
    <LineChart labels={R.map(r => `R${r.round}`)} titles={R.map(r => r.track)} sortDesc={false}
      series={names.map(n => ({ name: n, slot: S.drivers.find(d => d.name === n)?.color ?? null, values: R.map(r => res(n, r.round)?.gap_team_pct ?? null) }))}
      fmt={v => `${v.toFixed(v < 10 ? 2 : 1)}%`} height={260} ariaLabel="Pace gap to the team's fastest lap by round" />
  </Panel>
</div>

<div class="mt-3 grid gap-3 lg:grid-cols-2">
  <Panel title="Consistency" sub="How far the average race lap sits above each driver's own best lap. Smaller is more consistent.">
    <BarList label="Consistency by driver" bars={consistency.map(s => ({
      key: s.driver, label: s.driver, driver: true, slot: s.color, value: s.avg_consistency_pct!, text: pct(s.avg_consistency_pct),
      tip: { title: s.driver, lines: [{ value: pct(s.avg_consistency_pct), label: 'average lap slower than best' }] },
    }))} />
  </Panel>
  <Panel title="Practice → qualifying → race" sub="Season average gap to the team's best in each session">
    <div class="scroll-x">
      <table class="tbl min-w-[480px]">
        <thead><tr><th>Driver</th><th class="num">Practice</th><th class="num">Qualifying</th><th class="num">Race</th><th>Trend</th></tr></thead>
        <tbody>
          {#each sessions as s (s.n)}
            <tr><td><DriverTag name={s.n} /></td><td class="num">{pct(s.p)}</td><td class="num">{pct(s.q)}</td><td class="num strong">{pct(s.r)}</td><td class="dim">{s.trend}</td></tr>
          {/each}
        </tbody>
      </table>
    </div>
  </Panel>
</div>
