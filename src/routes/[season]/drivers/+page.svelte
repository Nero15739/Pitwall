<script lang="ts">
  import DriverTag from '$lib/components/DriverTag.svelte';
  import PageHead from '$lib/components/PageHead.svelte';
  import Panel from '$lib/components/Panel.svelte';
  import Segmented from '$lib/components/Segmented.svelte';
  import { slotColor } from '$lib/drivers';
  import { carShort, firstName, fix, lap, ord, pct, per10, trackTiny } from '$lib/format';
  import { tip } from '$lib/tip.svelte';
  import type { Result } from '$shared/types';

  let { data } = $props();
  const S = $derived(data.season);
  const R = $derived(S.rounds);
  const names = $derived(S.standings.map(s => s.driver));
  const slotOf = (n: string) => S.drivers.find(d => d.name === n)?.color ?? null;
  const trackOf = (r: number) => R.find(x => x.round === r)!;

  let kind = $state<'race' | 'qual'>('race');
  const M = $derived(kind === 'race' ? S.h2h_race : S.h2h_qual);

  let a = $state(''), b = $state('');
  const A = $derived(names.includes(a) ? a : names[0]);
  const B = $derived(names.includes(b) && b !== A ? b : names.find(n => n !== A)!);
  const rows = $derived(R.map(r => ({ r, x: S.results.find(x => x.driver === A && x.round === r.round), y: S.results.find(x => x.driver === B && x.round === r.round) }))
    .filter(v => v.x && v.y) as { r: (typeof R)[number]; x: Result; y: Result }[]);
  const tally = (f: (x: Result, y: Result) => number) => rows.reduce((t, v) => { const d = f(v.x, v.y); return [t[0] + Number(d < 0), t[1] + Number(d > 0)]; }, [0, 0]);
  const battles = $derived([
    ['Finished ahead', tally((x, y) => x.finish - y.finish)],
    ['Out-qualified', tally((x, y) => (x.qual ?? 1e9) - (y.qual ?? 1e9))],
    ['Faster race lap', tally((x, y) => (x.best ?? 1e9) - (y.best ?? 1e9))],
    ['Fewer incidents', tally((x, y) => x.inc - y.inc)],
  ] as [string, number[]][]);
  const sA = $derived(S.standings.find(s => s.driver === A)!);
  const sB = $derived(S.standings.find(s => s.driver === B)!);
</script>

<PageHead title="Head to head" sub="How everyone stacks up against each other, race by race." />

<Panel title="Head-to-head record" sub="Read across: how many times the row driver {kind === 'race' ? 'finished ahead of' : 'out-qualified'} the column driver">
  {#snippet actions()}
    <Segmented label="Record" bind:value={kind} options={[{ value: 'race', label: 'Race finishes' }, { value: 'qual', label: 'Qualifying' }]} />
  {/snippet}
  <div class="scroll-x">
    <table class="tbl min-w-[620px]">
      <thead><tr><th></th>{#each names as n (n)}<th class="text-center"><DriverTag name={n} label={firstName(n)} /></th>{/each}<th class="num">Won</th></tr></thead>
      <tbody>
        {#each names as r (r)}
          {@const won = names.reduce((t, c) => t + (c === r ? 0 : M[r][c]), 0)}
          {@const played = names.reduce((t, c) => t + (c === r ? 0 : M[r][c] + M[c][r]), 0)}
          <tr>
            <td class="max-w-0 min-w-32"><DriverTag name={r} /></td>
            {#each names as c (c)}
              {#if r === c}
                <td class="bg-muted/60"></td>
              {:else}
                {@const w = M[r][c]}
                {@const l = M[c][r]}
                <td class="text-center" use:tip={{ title: `${r} vs ${c}`, lines: [{ value: `${w}–${l}` }] }}>
                  <span class="tnum font-semibold">{w}</span><span class="tnum text-muted-foreground">–{l}</span>
                  <span class="mx-auto mt-1 block h-1 w-12 overflow-hidden rounded-full bg-muted">
                    <span class="block h-full rounded-full" style="width:{w + l ? (w / (w + l)) * 100 : 0}%; background:{slotColor(slotOf(r))}"></span>
                  </span>
                </td>
              {/if}
            {/each}
            <td class="num strong">{played ? Math.round((won / played) * 100) + '%' : '–'}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
</Panel>

<Panel class="mt-3" title="Duel" sub="Pick any two drivers to compare round by round">
  {#snippet actions()}
    <label class="sr-only" for="duel-a">First driver</label>
    <select id="duel-a" bind:value={() => A, v => (a = v)} class="h-8 rounded-lg border bg-card px-2 text-[13px]">
      {#each names as n (n)}<option value={n}>{n}</option>{/each}
    </select>
    <span class="text-xs text-muted-foreground">vs</span>
    <label class="sr-only" for="duel-b">Second driver</label>
    <select id="duel-b" bind:value={() => B, v => (b = v)} class="h-8 rounded-lg border bg-card px-2 text-[13px]">
      {#each names.filter(n => n !== A) as n (n)}<option value={n}>{n}</option>{/each}
    </select>
  {/snippet}
  <div class="grid gap-6 lg:grid-cols-2">
    <table class="tbl">
      <thead><tr><th>{rows.length} shared rounds</th><th class="num"><DriverTag name={A} label={firstName(A)} /></th><th class="num"><DriverTag name={B} label={firstName(B)} /></th></tr></thead>
      <tbody>
        {#each battles as [label, t] (label)}
          <tr><td>{label}</td><td class="num {t[0] > t[1] ? 'strong' : 'dim'}">{t[0]}</td><td class="num {t[1] > t[0] ? 'strong' : 'dim'}">{t[1]}</td></tr>
        {/each}
        <tr><td>Points</td><td class="num {sA.points > sB.points ? 'strong' : 'dim'}">{sA.points}</td><td class="num {sB.points > sA.points ? 'strong' : 'dim'}">{sB.points}</td></tr>
        <tr><td>Avg gap to team best lap</td><td class="num">{pct(sA.avg_gap_team_pct)}</td><td class="num">{pct(sB.avg_gap_team_pct)}</td></tr>
      </tbody>
    </table>
    <div class="scroll-x">
      <table class="tbl min-w-[420px]">
        <thead><tr><th>Round</th><th class="num">Finish</th><th class="num">Best lap gap</th><th class="num">Incidents</th></tr></thead>
        <tbody>
          {#each rows as v (v.r.round)}
            {@const d = v.x.best && v.y.best ? v.x.best - v.y.best : null}
            <tr>
              <td>R{v.r.round} {trackTiny(v.r.track_short)}</td>
              <td class="num">{ord(v.x.finish)} <span class="dim">v</span> {ord(v.y.finish)}</td>
              <td class="num" use:tip={{ title: `R${v.r.round} best laps`, lines: [{ value: lap(v.x.best), label: A }, { value: lap(v.y.best), label: B }] }}>
                {d == null ? '–' : `${firstName(d < 0 ? A : B)} ${Math.abs(d).toFixed(3)}s`}
              </td>
              <td class="num">{v.x.inc} <span class="dim">v</span> {v.y.inc}</td>
            </tr>
          {/each}
        </tbody>
      </table>
      <p class="mt-2 text-xs text-muted-foreground">Best lap gap names whoever was quicker, and by how much.</p>
    </div>
  </div>
</Panel>

<Panel class="mt-3" title="Season table" sub="Finishing positions are overall (including AI). Incident rate is per 10 laps completed.">
  <div class="scroll-x">
    <table class="tbl min-w-[860px]">
      <thead><tr><th>Pos</th><th>Driver</th><th class="num">Points</th><th class="num">Wins</th><th class="num">Podiums</th><th class="num">Poles</th>
        <th class="num">Avg start</th><th class="num">Avg finish</th><th class="num">Laps led</th><th class="num">Incidents</th><th class="num">Inc / 10 laps</th><th>Main car</th></tr></thead>
      <tbody>
        {#each S.standings as s (s.driver)}
          <tr>
            <td class="num dim">{s.pos}</td><td><DriverTag name={s.driver} /></td><td class="num strong">{s.points}</td>
            <td class="num">{s.wins}</td><td class="num">{s.podiums}</td><td class="num">{s.poles}</td>
            <td class="num">{fix(s.avg_start, 1)}</td><td class="num">{fix(s.avg_finish, 1)}</td><td class="num">{s.laps_led}</td>
            <td class="num">{s.inc}</td><td class="num">{per10(s.inc_per_lap)}</td><td class="dim">{carShort(Object.keys(s.cars)[0])}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
</Panel>

<h2 class="mt-8 mb-3 text-[15px] font-semibold tracking-tight">Driver profiles</h2>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
  {#each S.standings as s (s.driver)}
    {@const xs = S.results.filter(x => x.driver === s.driver)}
    {@const bestRes = [...xs].sort((p, q) => p.finish - q.finish)[0]}
    {@const q = xs.filter(x => x.team_qual).map(x => x.team_qual!)}
    {@const h = S.hardest_by_driver[s.driver]}
    {@const awards = S.awards.filter(w => w.driver.includes(s.driver)).map(w => w.title)}
    <div class="flex flex-col gap-4 rounded-xl border bg-card p-4" style="border-top:3px solid {slotColor(s.color)}">
      <div class="flex items-center justify-between gap-2">
        <span class="text-[15px] font-semibold"><DriverTag name={s.driver} /></span>
        <span class="tnum rounded-md bg-muted px-2 py-0.5 text-xs font-medium">P{s.pos} · {s.points} pts</span>
      </div>
      <div class="grid grid-cols-3 gap-3">
        {#each [[s.wins, 'Wins'], [s.podiums, 'Podiums'], [fix(s.avg_finish, 1), 'Avg finish'], [pct(s.avg_gap_team_pct), 'Pace gap'], [fix(s.inc_per_race, 1), 'Inc / race'], [s.ai_beaten, 'AI beaten']] as [v, l] (l)}
          <div><div class="text-lg font-semibold">{v}</div><div class="text-xs text-muted-foreground">{l}</div></div>
        {/each}
      </div>
      <ul class="grid gap-1 text-[13px] text-muted-foreground">
        <li>Best result <span class="font-medium text-foreground">{ord(bestRes.finish)}</span> at {trackOf(bestRes.round).track_short}</li>
        <li>Average team qualifying <span class="font-medium text-foreground">{q.length ? (q.reduce((x, y) => x + y, 0) / q.length).toFixed(1) : '–'}</span> of {S.drivers.length}</li>
        {#if h}<li>Worst incident rate at <span class="font-medium text-foreground">{trackOf(h.most_incidents.round).track_short}</span> ({h.most_incidents.inc}x)</li>{/if}
        <li>Cars <span class="font-medium text-foreground">{Object.entries(s.cars).map(([c, k]) => `${carShort(c)}${k > 1 ? ' ×' + k : ''}`).join(', ')}</span></li>
        {#if awards.length}<li>Awards <span class="font-medium text-foreground">{awards.join(', ')}</span></li>{/if}
      </ul>
    </div>
  {/each}
</div>
