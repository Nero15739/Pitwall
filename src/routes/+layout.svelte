<script lang="ts">
  import '../app.css';
  import { goto, invalidate } from '$app/navigation';
  import { page } from '$app/state';
  import { Moon, Sun } from '@lucide/svelte';
  import GlobalTip from '$lib/components/GlobalTip.svelte';

  let { data, children } = $props();

  const POLL_MS = 60_000;
  const slug = $derived(page.params.season ?? data.index.seasons[0]?.slug);
  const season = $derived(data.index.seasons.find(s => s.slug === slug));
  const nav = [
    { href: '', label: 'Overview' },
    { href: '/tracks', label: 'Tracks' },
    { href: '/laps', label: 'Lap times' },
    { href: '/incidents', label: 'Incidents' },
    { href: '/cars', label: 'Cars' },
    { href: '/drivers', label: 'Head to head' },
    { href: '/awards', label: 'Awards' },
  ];
  const section = $derived(page.url.pathname.split('/')[2] ?? '');
  const isCurrent = (href: string) => (href === '' ? section === '' : section === href.slice(1));

  // pick up rebuilds without a reload: the index changes whenever the compiler runs
  $effect(() => {
    const t = setInterval(async () => {
      try {
        const r = await fetch('/data/seasons.json', { cache: 'no-cache' });
        if (r.ok && (await r.json()).generated !== data.index.generated) await invalidate('pitwall:data');
      } catch { /* keep showing the last good data */ }
    }, POLL_MS);
    return () => clearInterval(t);
  });

  let theme = $state(typeof document !== 'undefined' ? document.documentElement.dataset.theme : 'light');
  function toggleTheme() {
    theme = theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem('pitwall-theme', theme); } catch { /* private mode */ }
  }

  function changeSeason(e: Event) {
    const next = (e.currentTarget as HTMLSelectElement).value;
    const rest = page.url.pathname.split('/').slice(2).filter(p => p !== '' && !/^\d+$/.test(p)).join('/');
    goto(`/${next}${rest ? '/' + rest : ''}`);
  }
</script>

<svelte:head><title>{season ? `${season.name} · Pit Wall` : 'Pit Wall'}</title></svelte:head>

<div class="flex min-h-dvh flex-col">
  <header class="sticky top-0 z-40 border-b bg-background/85 backdrop-blur supports-[backdrop-filter]:bg-background/70">
    <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 pt-3 sm:px-6">
      <a href="/{slug ?? ''}" class="flex min-w-0 items-center gap-2.5">
        <svg width="26" height="26" viewBox="0 0 32 32" aria-hidden="true" class="shrink-0">
          <rect width="32" height="32" rx="8" class="fill-foreground" />
          <path d="M7 9h4v4H7zm8 0h4v4h-4zm-4 4h4v4h-4zm8 0h4v4h-4zM7 17h4v4H7zm8 0h4v4h-4z" class="fill-background" />
        </svg>
        <span class="min-w-0 leading-tight">
          <span class="block text-[15px] font-semibold tracking-tight">Pit Wall</span>
          <span class="block truncate text-xs text-muted-foreground">{season?.league ?? 'Season dashboard'}</span>
        </span>
      </a>
      <div class="ml-auto flex items-center gap-2">
        {#if data.index.seasons.length > 1 || season}
          <label class="sr-only" for="season">Season</label>
          <select id="season" value={slug} onchange={changeSeason}
            class="h-8 max-w-44 rounded-lg border bg-card px-2 text-[13px] font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
            {#each data.index.seasons as s (s.slug)}
              <option value={s.slug}>{s.name} · {s.rounds} rnd{s.rounds === 1 ? '' : 's'}</option>
            {/each}
          </select>
        {/if}
        <button type="button" onclick={toggleTheme} aria-label="Switch to {theme === 'dark' ? 'light' : 'dark'} theme"
          class="grid size-8 place-items-center rounded-lg border bg-card text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
          {#if theme === 'dark'}<Sun size={16} />{:else}<Moon size={16} />{/if}
        </button>
      </div>
    </div>
    {#if slug}
      <nav aria-label="Pages" class="mx-auto max-w-6xl px-2 sm:px-4">
        <ul class="flex gap-0.5 overflow-x-auto py-1.5 [scrollbar-width:none]">
          {#each nav as n (n.href)}
            <li>
              <a href="/{slug}{n.href}" aria-current={isCurrent(n.href) ? 'page' : undefined}
                class="block rounded-md px-2.5 py-1.5 text-[13px] font-medium whitespace-nowrap text-muted-foreground transition-colors hover:bg-muted hover:text-foreground aria-[current=page]:bg-muted aria-[current=page]:text-foreground">
                {n.label}
              </a>
            </li>
          {/each}
        </ul>
      </nav>
    {/if}
  </header>

  <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 sm:py-8">
    {@render children()}
  </main>

  <footer class="mx-auto flex w-full max-w-6xl flex-wrap justify-between gap-x-6 gap-y-1 px-4 pb-6 text-xs text-muted-foreground sm:px-6">
    <span>Data compiled {new Date(data.index.generated).toLocaleString()} · refreshes automatically</span>
    <span>Track maps © iRacing.com via iRaceHUD · AI field shown as a benchmark</span>
  </footer>
</div>

<GlobalTip />
