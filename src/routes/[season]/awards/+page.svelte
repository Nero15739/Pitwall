<script lang="ts">
  import DriverTag from '$lib/components/DriverTag.svelte';
  import PageHead from '$lib/components/PageHead.svelte';
  import { slotColor } from '$lib/drivers';
  import {
    Bomb, Camera, Car, Clock, Crown, Flag, Heart, Medal, Rocket, Shield, ShieldAlert, Target, Timer, TrendingUp, Trophy, Bot, Wrench, Metronome,
  } from '@lucide/svelte';

  let { data } = $props();
  const S = $derived(data.season);
  const ICONS = {
    rocket: Rocket, bolt: Bomb, shield: Shield, 'shield-alert': ShieldAlert, metronome: Metronome, stopwatch: Timer,
    flag: Flag, trend: TrendingUp, crown: Crown, robot: Bot, car: Car, heart: Heart, target: Target, camera: Camera,
    trophy: Trophy, clock: Clock, wrench: Wrench,
  } as Record<string, typeof Trophy>;
  const slotOf = (n: string) => S.drivers.find(d => n.startsWith(d.name))?.color ?? null;
</script>

<PageHead title="Season awards" sub="The numbers behind the bragging rights, all computed from the race results." />

<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
  {#each S.awards as a (a.key)}
    {@const Icon = ICONS[a.icon] ?? Medal}
    <div class="flex flex-col gap-2 rounded-xl border bg-card p-4 sm:p-5" style="border-top:3px solid {slotColor(slotOf(a.driver))}">
      <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{a.title}</span>
        <Icon size={18} class="text-muted-foreground" aria-hidden="true" />
      </div>
      <div class="text-[1.75rem] leading-none font-semibold tracking-tight">{a.value}</div>
      <div class="text-[14px] font-medium">
        {#if a.driver.includes(' vs ')}
          {@const [x, y] = a.driver.split(' vs ')}
          <DriverTag name={x} /> <span class="text-muted-foreground">vs</span> <DriverTag name={y} />
        {:else}
          <DriverTag name={a.driver} />
        {/if}
      </div>
      <p class="text-[13px] text-muted-foreground">{a.detail}</p>
    </div>
  {/each}
</div>
