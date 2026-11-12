# Triathlon Timing

[![Laravel Forge Site Deployment Status](https://img.shields.io/endpoint?url=https%3A%2F%2Fforge.laravel.com%2Fsite-badges%2Fee9c2f10-94ad-4bea-89a9-ad51c7508bc1%3Fdate%3D1%26label%3D1%26commit%3D1&style=flat)](https://forge.laravel.com/bram-carlier/brotherhood/3392503)
[![Quality](https://github.com/BramCarlier/triathlon-timing/actions/workflows/quality.yml/badge.svg?branch=main)](https://github.com/BramCarlier/triathlon-timing/actions/workflows/quality.yml)

Race-day timing for small triathlons and relays. The Organizer sets up a race, shares one link with the volunteers at the transitions and the finish, starts the clock, and gets live standings and final results without timing chips.

- **One shared clock.** The server owns the race start; every screen shows the same time.
- **Account-free timing.** Volunteers open the race-day link on their phone, pick their checkpoint and tap athletes as they pass.
- **Built for weak signal.** Taps that cannot reach the server are kept on the phone with their original time and uploaded later.
- **Solo and relay entries.** Relay splits are attributed to the team member racing that leg.
- **Live and published results.** Live standings, splits per checkpoint, CSV/XLSX export, a printable PDF and an optional public leaderboard.
- **English and Dutch.**

## Contents

- [How race day works](#how-race-day-works)
- [How a tap is timed](#how-a-tap-is-timed)
- [Races, checkpoints and entries](#races-checkpoints-and-entries)
- [Accounts and roles](#accounts-and-roles)
- [Importing athletes](#importing-athletes)
- [Results](#results)
- [Local development](#local-development)
- [Tests and quality](#tests-and-quality)
- [Deployment](#deployment)
- [Documentation](#documentation)

## How race day works

1. **Set up the race.** Create it, check the default checkpoints (adjust only if needed), and add or import the athletes. A name is enough for a solo entry; bibs and other details are optional.
2. **Share the race-day link.** The race workspace shows the link with **Open**, **Copy** and **Share** buttons. Anyone with the link can time; no account is needed. Add `?checkpoint=FINISH` (any checkpoint code) to send someone straight to their checkpoint.
3. **Open it on each phone before the start**, while there is signal, and choose the checkpoint. The phone remembers it.
4. **Start the race clock** from the workspace when the race begins.
5. **Tap each athlete as they pass.** Search by bib number or name; athletes who already have a time at that checkpoint fold away. A mistaken tap can be undone for 60 seconds.
6. **The race finishes itself** when the last active athlete gets a finish time. Athletes still without one are marked DNF (they recover automatically if a late finish time is uploaded). The Organizer can also end the race manually.
7. **Review, correct and publish.** Corrections keep the original timing in the audit trail. Export CSV/XLSX/PDF or publish a public leaderboard.

The Organizer can switch the race-day link off at any time, or replace it with a new link; the old link stops working immediately.

For events where you want named, audited timers, create optional **Official** accounts, assign each to a checkpoint and let them use the signed-in timing station instead of the shared link.

## How a tap is timed

The race-day link, the Organizer workspace and the timing station all use the same tap screen and recorder:

- **Server clock, not the phone clock.** The race has one `started_at` stored by the server. Browsers sample `GET /time` a few times on load and every two minutes and keep the sample with the shortest round trip (the NTP midpoint method). Each tap is stamped on that server timeline, so a phone whose clock is several seconds off still records the right time. Reloading never resets the clock; after the finish it shows the fixed race duration.
- **No signal, no lost tap.** A tap that cannot reach the server is saved in IndexedDB with its original time and uploaded automatically when the connection returns. The race-day link accepts saved taps for up to 30 minutes; older ones need an Organizer correction.
- **Never twice.** Every tap carries a browser-generated UUID, so retries are idempotent. The server allows one active timing per entry and checkpoint, enforced under a row lock and by a unique index.
- **Undo.** The phone that tapped can undo it on the race-day link for 60 seconds. Officials and the Organizer can undo their own taps from the station or workspace.
- **Offline pages.** The service worker keeps the last good copy of each timing page, so a page opened online can be reopened without signal. Do not clear browser site data while taps are waiting to upload.

Every timing record stores the race, entry, checkpoint, the athlete for that discipline, the operator (`null` for the race-day link), UTC `recorded_at`, elapsed milliseconds, the source (`online`, `offline`, `manual`) and void audit data. Deleting a timing keeps the original row as voided.

## Races, checkpoints and entries

A new race gets six checkpoints:

1. Race Start
2. Swim Exit
3. T1 (Bike Start)
4. Bike Finish
5. T2 (Run Start)
6. Finish

Extra intermediate splits such as `SWIM_500M`, `BIKE_10K` or `RUN_4K` can be added; the sequence number decides the order. Naming and distance display: [`docs/CHECKPOINT_CONVENTIONS.md`](docs/CHECKPOINT_CONVENTIONS.md).

An **entry** is what crosses the line:

- `solo`: one athlete races swim, bike and run.
- `relay`: one swim, one bike and one run athlete.

Bibs are optional; if given, they must be unique within the race. Timings belong to the entry, and each split is attributed to the relay member for that discipline. Entry statuses are `registered`, `dns`, `dnf` and `dsq`.

## Accounts and roles

| Account | Needed? | Can |
| --- | --- | --- |
| **Organizer** (`admin`) | Yes, at least one | Create and configure races, add or import athletes, start and end the clock, share or rotate the race-day link, correct timings, export and publish results, manage accounts and Official permissions. |
| **Official** (`official`) | Optional | Time at their assigned checkpoint with the signed-in station, with their name on every tap. Further rights are configurable per account. |
| **Athlete** (`athlete`) | Optional | Sign in to see their own races and results. Athletes do not need an account to race. |

There is no public registration. Create the first Organizer from the command line (see below); further accounts are invited by e-mail from **People**. Disabling an account also ends its existing sessions. Deleted races can be restored from **Deleted races**; no history is purged. Details: [`docs/ROLES.md`](docs/ROLES.md).

## Importing athletes

Upload CSV, JSON, XLSX, XLS or ODS. The file is parsed into a preview before anything is saved. A CSV template can be downloaded in the app, and examples are in [`examples/`](examples).

Solo athletes use one row each:

```csv
bib,type,team_name,first_name,last_name,email,discipline,category,club
101,solo,,Jane,Doe,jane@example.com,,Open,Halle Tri Club
```

Relays use one row per member (`discipline` is `swim`, `bike` or `run`, once each):

```csv
200,relay,Fast Three,Sam,Swimmer,sam@example.com,swim,Relay,
200,relay,Fast Three,Ben,Biker,ben@example.com,bike,Relay,
200,relay,Fast Three,Rae,Runner,rae@example.com,run,Relay,
```

Bibs may be left out entirely. Relay rows are grouped by bib, or by `team_name` when there is no bib; for two teams with the same name and no bib, add a distinct `entry_key`. A wide layout with `swim_first_name`, `bike_first_name`, `run_first_name`, … on one row is also accepted.

## Results

- **While the race runs:** live standings by course progress, and provisional places on the results page.
- **Afterwards:** places by finish time (equal times share a place: 1, 1, 3), gap to the leader, cumulative time and split per checkpoint, and per-checkpoint places. DNS has no times; DNF and DSQ keep their times but get no place.
- **Export:** CSV and XLSX, and a PDF with every participant and checkpoint (A4 landscape, wider pages for extra checkpoints, labels in the chosen language).
- **Publish:** an optional public leaderboard at `/live/{token}` with the same PDF download. Unpublishing revokes both.
- **Display mode:** a full-screen results view for a screen at the finish.

## Local development

Requirements: PHP 8.5, Composer, Node.js 24 with Corepack (Yarn 4 is pinned in `package.json`), and MySQL 8 or SQLite.

```bash
cp .env.example .env
composer install
php artisan key:generate

# Set the DB_* values in .env, then:
php artisan migrate
php artisan app:create-admin admin@example.com --name="Race Director"

corepack enable
yarn install
```

Run these in separate terminals:

```bash
php artisan serve
php artisan reverb:start     # live updates for signed-in pages
yarn dev
php artisan queue:work       # only when QUEUE_CONNECTION is not sync
```

Useful commands: `php artisan timing:health` (queue, scheduler and Reverb status), `timing:check-reverb`, `timing:check-reverb-connection` and `timing:check-mail`.

### Stack

- Laravel 13 on PHP 8.5, Inertia 3, Laravel Reverb (WebSockets, loaded only on signed-in pages)
- Vue 3.5 + TypeScript, Vite 8, Tailwind CSS 4
- Barlow and Barlow Condensed (self-hosted), Lucide icons
- MySQL 8 in production; SQLite for tests (PostgreSQL is not tested)
- Laravel Excel / PhpSpreadsheet for import and export, Dompdf for PDFs
- IndexedDB and a service worker for offline timing

The interface is designed for timers standing outside: high contrast in light and dark themes, condensed numbers for clocks and bibs, and one colour per discipline (swim blue, bike yellow, run red, finish chequered). See [`docs/DESIGN.md`](docs/DESIGN.md).

## Tests and quality

```bash
php artisan test                # PHP feature tests (SQLite)
composer lint                   # Pint formatting check + Larastan (level 5, with baseline)
yarn quality                    # ESLint, Prettier, frontend unit tests, vue-tsc, production build
yarn test:browser               # Playwright end-to-end tests (Chromium)
yarn test:responsive            # layout checks at phone, tablet and desktop sizes
yarn test:responsive:webkit     # the same in WebKit

composer format && yarn format  # apply formatting
```

Every pull request runs `.github/workflows/quality.yml`: dependency audits, Pint, Larastan, the PHP suite, frontend lint, unit tests, type check and build, the Playwright and responsive suites, and the Feature and concurrency suites on MySQL 8.4. WebKit layout checks run when the workflow is started manually. Deployment script tests live in `tests/Deployment`.

## Deployment

Production runs on Laravel Forge, which deploys `main` automatically; the badge at the top shows the latest deployment. [`deploy/forge-deploy.sh`](deploy/forge-deploy.sh) installs dependencies, builds the frontend and only then runs migrations, and stops early if PHP 8.5 or Node 24 is missing.

[`deploy/FORGE.md`](deploy/FORGE.md) covers the site and `.env` setup, the Reverb daemon and WebSocket proxy, the queue worker and scheduler, the first administrator, e-mail through the Gmail API, release checks, backups with [`deploy/backup-database.sh`](deploy/backup-database.sh), uptime monitoring, and sharing the server with Brotherhood Manager.

## Documentation

| Document | About |
| --- | --- |
| [`deploy/FORGE.md`](deploy/FORGE.md) | Production setup, upgrades, backups and monitoring |
| [`docs/ROLES.md`](docs/ROLES.md) | Accounts, permissions and the race-day link controls |
| [`docs/CHECKPOINT_CONVENTIONS.md`](docs/CHECKPOINT_CONVENTIONS.md) | Checkpoint names and how distances are shown |
| [`docs/DESIGN.md`](docs/DESIGN.md) | Colours, type and interface components |
| [`docs/RELIABILITY_REVIEW.md`](docs/RELIABILITY_REVIEW.md) | Timing reliability decisions and known limits |
| [`docs/RESPONSIVE_QA.md`](docs/RESPONSIVE_QA.md) | What the responsive browser checks cover |
| [`docs/localisation.md`](docs/localisation.md) | English and Dutch, and how the language choice is kept |
