<script lang="ts">
  import LineChart from '$lib/components/charts/LineChart.svelte';
  import DriverTag from '$lib/components/DriverTag.svelte';
  import PageHead from '$lib/components/PageHead.svelte';
  import Panel from '$lib/components/Panel.svelte';
  import StatTile from '$lib/components/StatTile.svelte';
  import TrackOutline from '$lib/components/TrackOutline.svelte';
  import { slotColor } from '$lib/drivers';
  import { carShort, date, dateShort, firstName, fix, lap, per10, trackTiny } from '$lib/format';

  let { data } = $props();
  const S = $derived(data.season);
  const R = $derived(S.rounds);
  const st = $derived(S.standings);
  const lead = $derived(st[0]);
  const mostWins = $derived([...st].sort((a, b) => b.wins - a.wins || (a.avg_finish ?? 0) - (b.avg_finish ?? 0))[0]);
  const teamInc = $derived(R.reduce((a, r) => a + r.team_inc, 0));
  const hardest = $derived([...S.tracks].sort((a, b) => a.difficulty_rank - b.difficulty_rank)[0]);
  const hardestRound = $derived(R.find(r => r.round === hardest?.round));
  const series = $derived(S.drivers.map(d => ({ name: d.name, slot: d.color, values: S.progression[d.name] })));
  const slotOf = (name: string) => S.drivers.find(d => d.name === name)?.color ?? null;
</script>

<PageHead title="{S.season} season" sub="{S.league} · {R.length} rounds, {date(R[0].date)} to {date(R[R.length - 1].date)}" />

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
  <StatTile label="Championship leader" value={firstName(lead.driver)} unit="{lead.points} pts"
    meta={st[1] ? `${lead.points - st[1].points} pts clear of ${firstName(st[1].driver)}` : ''} />
  <StatTile label="Most wins" value={mostWins.wins} unit={firstName(mostWins.driver)}
    meta={st.filter(s => s.wins).map(s => `${firstName(s.driver)} ${s.wins}`).join(' · ') || 'No team wins yet'} />
  <StatTile label="Team incidents" value={teamInc} unit="{fix(teamInc / R.length, 1)} / round" meta="across {S.drivers.length} drivers" />
  <StatTile label="Toughest track" meta="{per10(hardest.inc_per_lap)} team incidents per 10 laps">
    <a href="/{S.slug}/tracks/{hardestRound?.track_id}" class="truncate text-xl underline-offset-4 hover:underline sm:text-2xl" title={hardest.track}>{trackTiny(hardest.track_short)}</a>
  </StatTile>
</div>

<div class="mt-3 grid gap-3 lg:grid-cols-3">
  <Panel title="Points progression" sub="Cumulative championship points after each round" class="lg:col-span-2">
    <LineChart labels={R.map(r => `R${r.round}`)} titles={R.map(r => r.track)} {series} yTitle="Points" ariaLabel="Cumulative points by round" />
  </Panel>
  <Panel title="Standings" sub="Team championship">
    <table class="w-full text-[13px]">
      <thead><tr class="border-b text-left text-xs text-muted-foreground">
        <th class="py-2 pr-2 font-medium">Pos</th><th class="py-2 pr-2 font-medium">Driver</th>
        <th class="py-2 pr-2 text-right font-medium">Pts</th><th class="py-2 pr-2 text-right font-medium">Gap</th><th class="py-2 text-right font-medium">Wins</th>
      </tr></thead>
      <tbody>
        {#each st as s (s.driver)}
          <tr class="border-b last:border-0">
            <td class="tnum py-2 pr-2 text-muted-foreground">{s.pos}</td>
            <td class="max-w-0 py-2 pr-2"><DriverTag name={s.driver} /></td>
            <td class="tnum py-2 pr-2 text-right font-semibold">{s.points}</td>
            <td class="tnum py-2 pr-2 text-right text-muted-foreground">{s.gap_to_leader ? '−' + s.gap_to_leader : '–'}</td>
            <td class="tnum py-2 text-right">{s.wins}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </Panel>
</div>

<h2 class="mt-8 mb-3 text-[15px] font-semibold tracking-tight">Rounds</h2>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
  {#each R as r (r.round)}
    {@const map = data.tracks?.tracks[String(r.track_id)]?.map}
    <a href="/{S.slug}/tracks/{r.track_id}"
      class="group flex flex-col gap-3 rounded-xl border bg-card p-4 transition-colors hover:border-axis focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
      style="border-top: 3px solid {r.winner_ai ? 'var(--ai)' : slotColor(slotOf(r.winner))}">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Round {r.round} · {dateShort(r.date)}</div>
          <div class="mt-0.5 text-[15px] leading-snug font-semibold">{r.track}</div>
        </div>
        <TrackOutline {map} class="h-10 w-16 shrink-0 text-muted-foreground/70 transition-colors group-hover:text-foreground" />
      </div>
      <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-[13px]">
        <dt class="text-muted-foreground">Winner</dt><dd class="min-w-0 text-right"><DriverTag name={r.winner} ai={r.winner_ai} /></dd>
        <dt class="text-muted-foreground">Pole</dt><dd class="min-w-0 text-right"><DriverTag name={r.pole} ai={r.pole_ai} /></dd>
        <dt class="text-muted-foreground">Fastest lap</dt>
        <dd class="truncate text-right"><span class="tnum">{lap(r.fastest?.time)}</span> <span class="text-muted-foreground">{r.fastest ? firstName(r.fastest.name) : ''}{r.fastest?.ai ? ' (AI)' : ''}</span></dd>
        <dt class="text-muted-foreground">Winning car</dt><dd class="truncate text-right">{carShort(r.winner_car)}</dd>
        <dt class="text-muted-foreground">Field</dt><dd class="text-right">{r.humans} + {r.ai} AI · {r.laps} laps</dd>
        <dt class="text-muted-foreground">Team incidents</dt><dd class="tnum text-right">{r.team_inc}</dd>
      </dl>
      <span class="mt-auto text-xs font-medium text-muted-foreground group-hover:text-foreground">
        {r.harvested ? 'Track map and crash hotspots →' : 'Track map and incidents →'}
      </span>
    </a>
  {/each}
</div>
