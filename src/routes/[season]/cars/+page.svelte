<script lang="ts">
  import BarList from '$lib/components/charts/BarList.svelte';
  import DriverTag from '$lib/components/DriverTag.svelte';
  import PageHead from '$lib/components/PageHead.svelte';
  import Panel from '$lib/components/Panel.svelte';
  import Segmented from '$lib/components/Segmented.svelte';
  import { carShort, firstName, fix, lap, ord, pct, per10, trackTiny } from '$lib/format';

  let { data } = $props();
  const S = $derived(data.season);
  const R = $derived(S.rounds);
  const names = $derived(S.standings.map(s => s.driver));
  const slotOf = (n: string) => S.drivers.find(d => d.name === n)?.color ?? null;

  let pick = $state('');
  const sel = $derived(R.find(r => r.round === +pick) ?? R[R.length - 1]);
  const cp = $derived(sel.car_pace);
</script>

<PageHead title="Cars" sub="What everyone drove, and which car was fastest at each track. Car pace uses the whole field, AI included, so every car has a benchmark." />

<Panel title="Fastest car at each track" sub="{sel.track} · best lap by each car in the field ({sel.starters} cars). Bars take the colour of whoever set the car's best lap; grey is AI.">
  {#snippet actions()}
    <Segmented label="Round" bind:value={() => String(sel.round), v => (pick = v)} options={R.map(r => ({ value: String(r.round), label: `R${r.round} ${trackTiny(r.track_short)}` }))} />
  {/snippet}
  <div class="grid gap-6 lg:grid-cols-2">
    <BarList label="Seconds off the fastest car" bars={cp.map(c => ({
      key: c.car, label: carShort(c.car), value: c.best - cp[0].best, text: c === cp[0] ? lap(c.best) : `+${(c.best - cp[0].best).toFixed(3)}s`,
      ai: c.ai, slot: c.ai ? null : slotOf(c.by),
      tip: { title: c.car, lines: [{ value: lap(c.best), label: `by ${c.by}${c.ai ? ' (AI)' : ''}` }, { value: `${c.entries}`, label: `in field · avg best ${lap(c.avg_best)}` }] },
    }))} />
    <div class="scroll-x">
      <table class="tbl min-w-[440px]">
        <thead><tr><th>#</th><th>Car</th><th class="num">Best lap</th><th>Set by</th><th class="num">In field</th></tr></thead>
        <tbody>
          {#each cp as c, i (c.car)}
            <tr><td class="num dim">{i + 1}</td><td>{carShort(c.car)}</td><td class="num {i === 0 ? 'strong' : ''}">{lap(c.best)}</td><td><DriverTag name={c.by} ai={c.ai} /></td><td class="num">{c.entries}</td></tr>
          {/each}
        </tbody>
      </table>
    </div>
  </div>
</Panel>

<Panel class="mt-3" title="Season summary by track" sub="The quickest car at each round and who drove it">
  <div class="scroll-x">
    <table class="tbl min-w-[720px]">
      <thead><tr><th>Rnd</th><th>Track</th><th>Fastest car</th><th>Set by</th><th class="num">Lap</th><th>Next car</th><th class="num">Gap</th><th>Team winner's car</th></tr></thead>
      <tbody>
        {#each R as r (r.round)}
          {@const a = r.car_pace[0]}
          {@const b = r.car_pace[1]}
          {@const tw = S.results.find(x => x.round === r.round && x.team_finish === 1)}
          <tr>
            <td class="num dim">{r.round}</td><td>{r.track_short}</td><td class="strong">{carShort(a?.car)}</td>
            <td>{#if a}<DriverTag name={a.by} ai={a.ai} />{:else}–{/if}</td><td class="num">{lap(a?.best)}</td>
            <td class="dim">{carShort(b?.car)}</td><td class="num">{b && a ? '+' + (b.best - a.best).toFixed(3) + 's' : '–'}</td>
            <td>{tw ? carShort(tw.car) : '–'}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
</Panel>

<Panel class="mt-3" title="Who drove what" sub="Each driver's car at every round, with their overall finish">
  <div class="scroll-x">
    <table class="tbl min-w-[640px]">
      <thead><tr><th>Driver</th>{#each R as r (r.round)}<th>R{r.round} {trackTiny(r.track_short)}</th>{/each}</tr></thead>
      <tbody>
        {#each names as n (n)}
          <tr>
            <td class="max-w-0 min-w-32"><DriverTag name={n} /></td>
            {#each R as r (r.round)}
              {@const x = S.results.find(x => x.driver === n && x.round === r.round)}
              <td>{#if x}<span class="dim">{carShort(x.car)}</span> <span class="tnum rounded bg-muted px-1.5 py-0.5 text-xs">{ord(x.finish)}</span>{:else}<span class="dim">–</span>{/if}</td>
            {/each}
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
</Panel>

<Panel class="mt-3" title="Car performance for the team" sub="How each car did in the team's hands across the season">
  <div class="scroll-x">
    <table class="tbl min-w-[760px]">
      <thead><tr><th>Car</th><th class="num">Starts</th><th>Drivers</th><th class="num">Wins</th><th class="num">Avg finish</th><th class="num">Gap to team best</th><th class="num">Inc / 10 laps</th><th class="num">Fastest car at</th></tr></thead>
      <tbody>
        {#each S.cars as c (c.car)}
          <tr>
            <td class="strong">{c.car}</td><td class="num">{c.starts}</td>
            <td><span class="flex flex-wrap gap-x-2 gap-y-1">{#each c.drivers as d (d)}<DriverTag name={d} label={firstName(d)} />{/each}</span></td>
            <td class="num">{c.wins}</td><td class="num">{fix(c.avg_finish, 1)}</td><td class="num">{pct(c.avg_gap_team_pct)}</td>
            <td class="num">{per10(c.avg_inc_per_lap)}</td><td class="num">{c.fastest_at.length ? c.fastest_at.map(r => 'R' + r).join(', ') : '–'}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
  <p class="mt-3 text-xs text-muted-foreground">With only a handful of starts per car these are indications, not verdicts. A car's numbers say as much about who drove it as about the car.</p>
</Panel>
