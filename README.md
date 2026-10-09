# Pit Wall – iRacing league dashboard

Season standings, lap times, incidents, cars, head-to-heads and awards for the team's league, plus a
page per track with the circuit map, **crash hotspots** and **sector splits** taken from the race replays.

Everything is computed once by a compiler and saved as JSON; the site is static files served by
nginx, so pages load instantly and nothing is calculated per request.

## Folder layout

```
Iracing-Seasons/
├── data/                      ← your files (git-ignored)
│   ├── Europe/                   one folder per season, any name
│   │   └── eventresult-*.json    one export per race
│   ├── incidents/                harvested replays: incidents-<subsession>.json
│   └── track-overrides.json      optional map calibration fixes
├── src/                       ← the web app (SvelteKit + Tailwind)
├── tools/                     ← compiler, watcher, replay harvester (TypeScript, run directly by Node)
├── deploy/                    ← setup.bat / setup.ps1, harvest.bat, nginx config template
├── public/                    ← what nginx serves (git-ignored): app/ and data/
├── .cache/                    ← per-race and track-map cache, logs (safe to delete)
├── pitwall.config.json        ← paths and port
└── sessionstats.ipynb         ← ad-hoc analysis notebook (reads data/)
```

Every folder in `data/` holding `eventresult-*.json` files is a season. Rounds are ordered by race
date, so file names don't matter.

## Adding a race

Drop the exported JSON into its season folder. The watcher compiles it within a few seconds and open
pages pick it up within a minute. To compile by hand: `npm run compile` (`-- --force` ignores the cache).

## Crash hotspots and sector splits (from replays)

Race exports and iRacing's web API don't say *where* incidents happened, and neither has sector
times. The replay does, so the harvester reads it through the iRacing SDK on the PC running iRacing:

1. See which races still need it: `npm run harvest -- --pending`
2. Open the race replay in iRacing (double-click `subses<id>.rpy` in `Documents\iRacing\replay`),
   or let the harvester open it: `npm run harvest -- --open <subsession>`
3. Run `npm run harvest` (or double-click `deploy\harvest.bat`) and leave iRacing alone until it finishes.

It plays the race at 16× (so it takes about race length ÷ 16, roughly two minutes for a 30-minute
race) and, from the same telemetry, times every car across the track's official sector lines and
spots incidents: a car leaving the circuit, or rolling backwards in a spin. The result lands in
`data/incidents/incidents-<subsession>.json` and the watcher compiles it. Use `--force` to redo a race.

If long jumps in the replay stall, turn off **Replay spooling** in iRacing's replay options.

## Running it on this PC

Needs Node.js 24 or newer. Double-click **`deploy\setup.bat`**. It:

1. installs packages and builds the web app if needed
2. installs nginx to `%LOCALAPPDATA%\pitwall-nginx` (no admin rights) serving http://localhost:8080, this PC only
3. compiles the data and starts nginx and the watcher in the background
4. adds `PitWall.vbs` to your Startup folder so it all starts when you log in

Run it again any time to repair or update. Options (`deploy\setup.bat -Option`):

| Option | What it does |
|---|---|
| `-Build` | rebuild the web app (after changing anything in `src/`) |
| `-Tunnel` | share the site through a Cloudflare quick tunnel |
| `-TunnelToken <token>` | share it through your own named tunnel and domain |
| `-NoTunnel` | stop sharing; local only again |
| `-Reload` | reload nginx after editing its config |
| `-Stop` | stop nginx, the watcher and the tunnel |
| `-Uninstall` | stop everything and remove nginx, cloudflared and the startup entry |
| `-Port 8090` | use another port if 8080 is taken |

Logs: nginx in `%LOCALAPPDATA%\pitwall-nginx\logs`, the watcher in `.cache\watcher.log`, the tunnel in `.cache\tunnel.log`.

## Sharing it

nginx only listens on this PC. A Cloudflare Tunnel makes an outbound connection to Cloudflare and
serves the site from there, so no ports are opened on your router.

- **Quick look:** `deploy\setup.bat -Tunnel` prints a `https://….trycloudflare.com` address. No account
  needed, but the address changes every time the tunnel restarts.
- **Your own domain:** in the Cloudflare dashboard (Zero Trust → Networks → Tunnels) create a tunnel,
  add a public hostname for your domain pointing at `http://localhost:8080`, copy the tunnel token and
  run `deploy\setup.ps1 -TunnelToken <token>`. The token is kept in your user profile, not in this folder.
- The pages show members' real names. To keep it to the team, add a Cloudflare Access policy for
  that hostname that allows only your team's email addresses.

## Moving to an always-on machine

The site is static files plus a small watcher, so any always-on box works (a mini PC, NAS or Raspberry Pi):

1. Install Node 24, nginx and cloudflared, and clone this repo.
2. Make `data/` available there, for example with OneDrive or rclone syncing from this PC,
   or point `dataDir` in `pitwall.config.json` at the synced folder.
3. `npm ci && npm run build`, copy `deploy/nginx.conf.tmpl` into nginx with real paths, and run
   `node tools/watch.ts --log` as a service.
4. Run cloudflared with the same tunnel token. The domain moves with it.

The harvester stays on the PC with iRacing; its output files sync across with the rest of `data/`.

## Developing

```
npm run dev        # the app on http://localhost:5173 (serves public/data too)
npm run compile    # rebuild public/data
npm test           # compiler, SDK reader and split-timer tests
npm run check      # type-check the app and the tools
npm run build      # production build into public/app
```

The compiled-data contract lives in `src/lib/shared/types.ts`, shared by the tools and the app.

## Notes on the stats

- Most of each field is AI. Team stats, standings and awards cover human drivers only, and AI times
  are used as a pace benchmark.
- Lap stats use each driver's best and average race laps from the export; sector splits come from the replay.
- Incident rates are per 10 laps completed, so short and long races compare fairly.
- Track difficulty blends the team's incident rate (50%), lap consistency (30%) and average places
  lost from the grid (20%).
- Hotspots cluster the incidents spotted in the replay around the lap (±2% windows). Telemetry shows
  a car leaving the circuit or spinning, but not contact that ends in neither, so the map undercounts
  the official incident points. On the Nürburgring round, per-driver counts tracked the official
  points with a correlation of 0.90. (iRacing's own "next incident" replay search was tried first;
  in these replays it just steps forward five seconds at a time, so it isn't used.)
- Splits are interpolated between replay samples (about ¼ s apart at 16×). Timed best laps matched
  iRacing's official best laps to within 0.005 s. A sector where the car left the track or spun on
  that lap (a cut chicane is quicker) doesn't count toward bests, laps more than 0.1 s quicker than
  the official best are ignored as cuts, and averages use incident-free laps within 107% of the
  driver's best. Laps touching pit road are left out.
- Driver colours are assigned alphabetically across every season, so each driver keeps the same
  colour and marker shape everywhere.
- Track maps are iRacing's official SVGs (© iRacing.com) as mirrored, with lap-distance calibration,
  by the open-source [iRaceHUD](https://github.com/xikxp1/iRaceHUD) project. They're fetched once per
  track and cached. Fix a misaligned map with `data/track-overrides.json`:
  `{ "250": { "offset": 0.516, "direction": 1 } }`.
