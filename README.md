# Triathlon Timing

A race-day web application for organizers and athletes. It supports a shared race clock, configurable checkpoint stations, solo and trio relay entries, participant imports, one-tap split recording, offline timing queues, live checkpoint presence, corrections, and results.

## Stack

- Laravel 13 on PHP 8.5
- Vue 3.5 + TypeScript + Inertia 3
- Vite 8 + Tailwind CSS 4, Barlow / Barlow Condensed (self-hosted), Lucide icons
- MySQL 8 in production; SQLite for local tests and browser tests (PostgreSQL is not tested)
- Laravel Reverb for live race events (loaded only on signed-in pages)
- Laravel Excel / PhpSpreadsheet for spreadsheet import/export
- IndexedDB + service worker support for race-day resilience

## Race model

The race has one authoritative `started_at` timestamp stored by the server. Browsers derive the displayed chronometer from that timestamp and a server-time anchor; they do not increment an independent stopwatch. Timestamps are serialized in UTC with an explicit timezone marker, so the clock starts at zero in every browser timezone. A browser reload does not reset the clock. After finishing, the clock displays the fixed difference between `finished_at` and `started_at`.

The normal race-day flow uses an unguessable share link. Anyone with that link can open the race without an account, choose an active checkpoint or finish, and tap the correct athlete to record the current race time. The backend validates race/checkpoint/entry relationships and warns when required earlier checkpoints are missing. Authenticated timing stations remain available as an optional advanced mode for named Officials.

### How a tap is timed

Every timing screen (the race-day link, the Organizer workspace and the timing station) uses the same tap pad and recorder:

- **Server clock, not the phone clock.** Browsers sample `GET /time` a few times on load and every two minutes, and use the round trip with the shortest delay to map their clock onto the server's (the NTP midpoint method). A tap is stamped with that estimate, so a phone whose clock is several seconds off still records the right time.
- **No signal, no lost tap.** If a tap cannot reach the server it is saved on the device (IndexedDB) with its original time and uploaded automatically when the connection returns. The race-day link accepts such saved taps for up to 30 minutes; older ones need an Organizer correction. Each tap carries a UUID, so a retry never creates a duplicate.
- **Undo.** The phone that made a tap on the race-day link can undo it for 60 seconds. Officials and the Organizer can undo their own taps from the station or workspace.
- **The checkpoint is remembered.** The race-day link asks where you are timing on first open and remembers it per device (also in the address as `?checkpoint=FINISH`), so a reload never silently switches to another checkpoint.
- **Athletes still to come first.** Athletes who already have a time at the selected checkpoint fold away; search accepts bib numbers (with a number keypad) or names.

The Organizer can switch the race-day link off or replace it with a new link from the workspace; the old link stops working immediately.

### Default checkpoint order

A new race gets:

1. Race Start
2. Swim Exit
3. T1 (Bike Start)
4. Bike Finish
5. T2 (Run Start)
6. Finish

Admins/organizers can add arbitrary extra splits such as `SWIM_500M`, `BIKE_10K`, `RUN_4K`, etc. Checkpoint sequence numbers determine progression.

## Participant model

An **entry** may have a bib number. Bibs are optional; supplied bibs must be unique within a race. Entries without a bib are identified by athlete or team name. An entry is either:

- `solo`: one athlete is linked to swim, bike and run.
- `relay`: one swim athlete, one bike athlete and one run athlete.

This means timing is always attached to the race entry while the app can still attribute a discipline split to the correct relay athlete.

## Accounts and roles

Only the Organizer account is required for normal setup. The race-day timing link is intentionally account-free: people helping at transitions or the finish open the link, choose the checkpoint and tap athletes.

### Organizer (admin)

- create/configure races
- add or import athletes
- start/finish race clocks
- share the race-day timing link
- correct timings and export/publish results
- optionally create and assign Official or Athlete accounts
- manage roles and advanced access

### Official — optional

A named Official account can be assigned to a checkpoint when you want account-specific permissions, audit identity, exports or the authenticated offline timing station. It is not required for normal timing from the shared link.

### Athlete — optional

Athletes do not need accounts to be entered in a race. A linked Athlete account is only needed when that athlete should sign in to see a personal race dashboard/history.

There is no public organizer/admin registration. Create the first administrator from the command line.

## Installation

```bash
cp .env.example .env
composer install
php artisan key:generate

# Configure DB_* values in .env
php artisan migrate
php artisan app:create-admin admin@example.com --name="Race Director"

corepack enable
yarn install
yarn build

# Commit composer.lock and yarn.lock after the first dependency install for reproducible deploys.
```

For local development, use separate terminals:

```bash
php artisan serve
php artisan reverb:start
yarn dev
```

If queues are configured asynchronously:

```bash
php artisan queue:work
```

## Import formats

Supported input files:

- CSV
- JSON
- XLSX
- XLS
- ODS

An upload is parsed into a preview before confirmation.

### Solo row

```csv
bib,type,team_name,first_name,last_name,email,discipline,category,club
101,solo,,Jane,Doe,jane@example.com,,Open,Halle Tri Club
```

### Relay rows

```csv
200,relay,Fast Three,Sam,Swimmer,sam@example.com,swim,Relay,
200,relay,Fast Three,Ben,Biker,ben@example.com,bike,Relay,
200,relay,Fast Three,Rae,Runner,rae@example.com,run,Relay,
```

Bibs may be omitted from every format, including the entire CSV/XLSX column. Solo athletes each occupy their own row. Relay rows share the same bib when supplied; otherwise they are grouped by `team_name`. For teams with the same name and no bib, use a distinct `entry_key` for each team. `discipline` must be `swim`, `bike`, or `run`, exactly once per relay.

A wide relay spreadsheet is also accepted when a single row contains fields such as `swim_first_name`, `swim_last_name`, `bike_first_name`, and `run_first_name`.

Examples are in `examples/` and a CSV template is downloadable inside the application.

## Timing safeguards

Every timing record has:

- a browser-generated UUID for idempotency
- race, entry and checkpoint IDs
- discipline athlete attribution where applicable
- operator ID for authenticated timing stations; `null` for the shared race-day link
- exact UTC `recorded_at`
- integer elapsed milliseconds
- source (`online`, `offline`, `manual`)
- warning acknowledgement state
- void audit data

The service prevents duplicate active timings at the same checkpoint. Voiding a timing retains the original audit row and allows a new timing to be recorded.

## Offline behavior

Both the race-day link and the authenticated timing station save taps that cannot reach the server in IndexedDB with their UUID and observed time, and retry them after reconnection. The UUID makes retries idempotent. The service worker keeps the last good copy of each timing page, so a page that was opened online can be reopened without signal.

Open the timing page on each device while connectivity is good before the race starts. Do not clear browser site data while taps are waiting to upload.

## Results

Results are derived from active timing records. The app shows cumulative checkpoint time and per-checkpoint split time, and exports CSV/XLSX files. Published results also offer a direct **Download complete results (PDF)** button to anyone with the published link. The PDF includes every participant and checkpoint, regardless of screen filters, with millisecond times, checkpoint places, splits, statuses and relay members. Standard courses use A4 landscape with repeated column headers and page numbers; courses with extra checkpoints use wider landscape pages so columns are never cut off. Labels follow the selected English/Dutch language. Unpublishing revokes PDF access along with the leaderboard link. Authorized staff can also download the PDF from their results page.

## Tests and quality

```bash
php artisan test            # PHP feature tests (SQLite)
composer lint               # Pint formatting check + Larastan (level 5, with baseline)
yarn quality                # ESLint, Prettier check, frontend unit tests, vue-tsc, build
yarn test:browser           # Playwright smoke tests (Chromium)
yarn test:responsive        # layout checks at phone, tablet and desktop sizes

# Formatting
composer format && yarn format
```

Pull requests run all of the above in `.github/workflows/quality.yml`, plus WebKit layout checks and the Feature and concurrency suites on MySQL 8.4. Laravel Forge deploys `main`; there is no separate deploy workflow.

## Laravel Forge

See [`deploy/FORGE.md`](deploy/FORGE.md). It covers:

- production `.env`
- PHP/database setup
- Reverb daemon and WebSocket proxy
- queue worker
- scheduler
- first administrator
- deploy script

## Race-day checklist

1. Add or import the athletes. A name is enough for a normal solo entry; bibs and other details are optional.
2. Check the default transition/finish checkpoints and adjust only if needed.
3. Open, copy or share the race-day link with the people timing the race. A link per checkpoint is possible: add `?checkpoint=FINISH` (the checkpoint code).
4. On each device, open that link while online and choose the transition/checkpoint or finish.
5. Start the shared race clock when the race begins.
6. Tap/search the correct athlete as they cross the selected timing point.
7. The race finishes automatically when the last active athlete receives a finish time, or the Organizer can end it manually.
8. Review and export/publish results.

For poor-connectivity events, optionally create named Official accounts and use the authenticated full-screen timing station with its offline queue and recovery tools.

## Navigation and account management

The primary Organizer workflow lives in one Race workspace: course, athletes, race-day link, race clock, timing and results. The separate participant list, full-screen timing station and race-control pages remain available as secondary tools for bulk editing, offline recovery, corrections and station health.

Use People only when you need optional named Official or Athlete accounts. Official permissions and checkpoint assignments apply to those account-based stations; athlete access is scoped to the linked athlete profile. At least one Organizer (admin) account must remain active. Disabling an account also blocks its existing sessions on their next request.

Deleting a race is reversible from the Deleted races section on the race list. No participant, checkpoint or timing history is purged. Deleted races are hidden from organizers and athletes until an administrator restores them.

## Design

The interface uses a small design system built for timers standing outside: high-contrast light and dark themes, condensed numerals for clocks and bibs, and one colour per discipline (swim blue, bike yellow, run red, finish chequered). See [`docs/DESIGN.md`](docs/DESIGN.md).
