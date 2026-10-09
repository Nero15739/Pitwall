# Pit Wall – iRacing league site

Season standings, lap times, incidents, cars, head-to-heads and awards for the league, plus a page
per track with the circuit map, **crash hotspots** and **sector splits** taken from the race replays.

Pit Wall 3 is a PHP site built for ordinary web hosting (Hostinger), with a brutalist design, an
admin panel for uploads, and an API so the iRacing PC can push results and replay harvests by itself.

```
 iRacing PC (this repo)                         Hostinger (web/ → public_html)
 ─────────────────────                          ──────────────────────────────
 data/<season>/eventresult-*.json ──┐           /api/v1/results   ┐
 replay harvester → data/incidents ─┼─ push ──▶ /api/v1/incidents ├─▶ database ─▶ compiler ─▶ cached JSON ─▶ pages
 watcher (pushes new files)  ───────┘           /admin  (upload)  ┘   (SQLite)    (on upload)              (~5 ms)
```

Every stat is computed once, when a file arrives, and saved; a page view only formats saved
numbers, so pages render in a few milliseconds and the hosting plan does almost no work.

## Why PHP (and not a Node "web app" or FastAPI)

| | PHP site (this) | Hostinger Node.js web app | FastAPI (Python) |
|---|---|---|---|
| Hostinger plans | every plan | Business and Cloud only, limited number of apps | VPS only (you run the server) |
| Moving parts | none; PHP runs per request | a process that must stay up, plus builds on deploy | a server, a process manager, a reverse proxy |
| Deploy | upload one 110 KB zip | upload/build/restart | SSH, systemd, nginx, TLS |
| API for pushing data | built in (`/api/v1`) | would need writing | its whole purpose |

The API is plain PHP behind an API key, so there's nothing extra to host. FastAPI would only
make sense on a VPS, which costs more and needs looking after.

## Folder layout

```
Iracing-Seasons/
├── web/                     ← the website: upload this to public_html (see "Deploying")
│   ├── index.php               front controller
│   ├── app/lib/                PHP: compiler (Stats/), database, admin, API
│   ├── app/views/              page templates
│   ├── assets/                 CSS (brutalist design system) and two small scripts, no framework
│   ├── bin/pitwall.php         command-line tools (import, compile, reset password, keys)
│   └── config.sample.php       optional settings; copy to config.php
├── tools/                   ← runs on the iRacing PC (Node 24, TypeScript)
│   ├── harvest.ts              replay harvester: crash locations + sector splits
│   ├── push.ts                 sends files to the site's API
│   ├── watch.ts                pushes new files as they land in data/
│   └── compile.ts, stats/      the original compiler (the PHP one matches it number for number)
├── data/                    ← your files (git-ignored): data/<season>/eventresult-*.json, data/incidents/
├── deploy/                  ← setup.bat (PC), harvest.bat, package-web.ps1 (makes the upload zip)
└── tests/parity.php         ← proves the PHP compiler publishes exactly the v2 numbers
```

## Deploying to Hostinger

1. **PHP version.** hPanel → Websites → your site → Advanced → PHP Configuration: PHP 8.2 or newer
   (8.3 recommended). The extensions Pit Wall needs (pdo_sqlite, curl, mbstring, zlib) are on by default.
2. **Package the site** on this PC: `npm run package` (or run `deploy\package-web.ps1`). It writes
   `dist\pitwall-web-3.0.0.zip`.
3. **Upload.** hPanel → Files → File Manager → `public_html`. Remove Hostinger's placeholder page,
   upload the zip, right-click it → Extract, and extract **into public_html itself** (you should then see
   `index.php`, `.htaccess`, `app`, `assets` directly inside `public_html`). Delete the zip.
4. **HTTPS.** hPanel → Security → SSL: make sure the free certificate is active, and turn on "Force HTTPS".
5. **Create your admin account.** Open `https://your-domain/admin`. The first visit creates a one-time
   setup token in `pitwall-storage/INSTALL-TOKEN.txt`, a folder beside `public_html` (outside the web root,
   so nobody can download it). Open it in File Manager, paste the token, choose a username and a password
   of 12+ characters. The token file is deleted once your account exists.
6. **Make an API key.** Admin → API keys → "Harvester PC". Copy it; it's shown once.
7. **Connect this PC** (next section) and push your existing races: `npm run push -- --all`.

**Updating the site later:** package again and extract over the top. Your data lives in
`pitwall-storage` and your settings in `config.php`, and neither is in the zip, so both survive.

**Database:** SQLite, a single file in `pitwall-storage`, needs no setup and is plenty for a league.
To use a Hostinger MySQL database instead, create one in hPanel → Databases and fill in `db` in
`config.php` (copy `config.sample.php`). The MySQL path hasn't been run against a real server yet;
SQLite is the tested default.

**Backups:** download `pitwall-storage` from File Manager now and then. It holds the database, which
keeps every uploaded file, so the site can always be rebuilt from it. Your `data/` folder on the PC is a
second copy: `npm run push -- --all --force` re-uploads everything to a fresh install.

## Getting data in

There are three ways in. All of them check the file, store it, and republish the site within a
second or so:

- **Admin panel** (`/admin`): drop any number of `eventresult-*.json` and `incidents-*.json` files on
  the upload box. Results go into the season you pick, or one is chosen automatically: a re-upload stays
  where it was, a race from a league season the site already has joins that season, otherwise a new
  season named after the league season. Races can be moved between seasons, seasons renamed (the
  name is the web address) and files removed under **Races**.
- **The PC tools** (below): push files from `data/` over the API.
- **The API** directly, from any script:

| Call | Does |
|---|---|
| `GET /api/v1/health` | public: version, when data was last published |
| `GET /api/v1/status` | seasons, races, and which replays still need harvesting |
| `POST /api/v1/results?season=Name` | body: an iRacing event result export |
| `POST /api/v1/incidents` | body: a harvested replay file |
| `POST /api/v1/compile` | republish (uploads do this themselves unless `?compile=0`) |

Send the key as `Authorization: Bearer <key>` (or `X-API-Key`). Bodies may be gzipped with
`Content-Encoding: gzip`. Each key is limited to 120 requests a minute. Errors come back as
`{"error": {"code": "...", "message": "..."}}` with a matching HTTP status.

```
curl -X POST "https://your-domain/api/v1/results?season=Europe" -H "Authorization: Bearer $PITWALL_API_KEY" --data-binary @eventresult-89139124-Nurburg-GP.json
```

## The iRacing PC

Needs Node.js 24+. Once:

```
deploy\setup.bat -Site https://your-domain -Key pw_your_key
```

That saves the address in `pitwall.config.json` and the key in `.pitwall-key` (git-ignored), checks the
connection, and starts the **watcher** hidden at login. The watcher pushes every new race export dropped
into `data\<season>\` and every new harvest. It also retires the v2 local web server (nginx and the
Cloudflare tunnel) if it's still installed; add `-RemoveOldServer` to delete it.

| | |
|---|---|
| `npm run push -- --all` | push everything under `data/` that the site doesn't have yet |
| `npm run push -- --status` | what the site holds, and which replays still need harvesting |
| `npm run push -- --season "Asia" <file>` | push a race into a particular season |
| `npm run harvest -- --pending` | races still to harvest, and whether the replay is on this PC |
| `npm run harvest -- --open <subsession>` | open that replay in iRacing and harvest it (uploads when done) |

Race exports go into the season named after their folder: `data\Europe\eventresult-….json` → "Europe".

### Crash hotspots and sector splits (from replays)

Race exports don't say *where* incidents happened, and have no sector times. The replay does, so the
harvester reads it through the iRacing SDK:

1. `npm run harvest -- --pending` (or `deploy\harvest.bat --pending`) lists what's missing.
2. `npm run harvest -- --open <subsession>` opens the replay in iRacing and harvests it. Leave iRacing
   alone until it finishes (about race length ÷ 16).
3. The result is saved in `data\incidents\incidents-<subsession>.json` and uploaded straight away.

If long jumps in the replay stall, turn off **Replay spooling** in iRacing's replay options.

## Running the site on this PC

With PHP 8.2+ installed (`winget install PHP.PHP.8.3`):

```
php web/bin/pitwall.php import data
```

```
npm run serve
```

The first loads `data/` into a local database (`web/storage`, git-ignored); the second serves the site at
http://127.0.0.1:8090. Create a local admin with `php web/bin/pitwall.php create-admin <name>`, and an API
key with `php web/bin/pitwall.php create-key <name>`.

Checks: `npm test` (harvester and compiler), `npm run check` (types), `npm run test:php` (the PHP
compiler against the TypeScript one, value by value).

## Security

- One admin account. Passwords are bcrypt-hashed; sign-in is throttled (5 failures per address per
  15 minutes); sessions are HttpOnly, SameSite=Strict cookies on `/admin` only; every form carries a CSRF token.
- API keys are random 240-bit tokens. Only their SHA-256 is stored, so a key is shown once. Revoke one
  from the admin panel at any time.
- The database, published data, sessions and logs live outside `public_html`. `app/`, `bin/` and
  `config.php` are blocked by `.htaccess`.
- Pages send a strict Content-Security-Policy (scripts only from the site, with a per-request nonce),
  `X-Frame-Options: DENY`, `nosniff`, and HSTS over HTTPS.
- The pages are public and show members' real names. To keep them to the team, put Cloudflare Access
  in front of the site and exempt `/api/*` (the push tools authenticate with their own key). Don't use
  hPanel's directory password for this: it would block the API too.

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
  points with a correlation of 0.90.
- Splits are interpolated between replay samples (about ¼ s apart at 16×). Timed best laps matched
  iRacing's official best laps to within 0.005 s. A sector where the car left the track or spun on
  that lap doesn't count toward bests, laps more than 0.1 s quicker than the official best are ignored
  as cuts, and averages use incident-free laps within 107% of the driver's best. Pit laps are left out.
- Driver colours are assigned alphabetically across every season, so each driver keeps the same
  colour and marker shape everywhere.
- Track maps are iRacing's official SVGs (© iRacing.com) as mirrored, with lap-distance calibration,
  by the open-source [iRaceHUD](https://github.com/xikxp1/iRaceHUD) project, fetched once per track.
  Fix a misaligned map in Admin → Settings → Track map calibration: `{ "250": { "offset": 0.516, "direction": 1 } }`.
