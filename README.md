# Triathlon Timing

A race-day web application for organizers and athletes. It supports a shared race clock, configurable checkpoint stations, solo and trio relay entries, participant imports, one-tap split recording, offline timing queues, live checkpoint presence, corrections, and results.

## Stack

- Laravel 13 / PHP 8.3+
- Vue 3 + TypeScript + Inertia
- Vite 8 + Tailwind CSS 4
- MySQL or PostgreSQL
- Laravel Reverb for live race events
- Laravel Excel / PhpSpreadsheet for spreadsheet import/export
- IndexedDB + service worker support for race-day resilience

## Race model

The race has one authoritative `started_at` timestamp stored by the server. Browsers derive the displayed chronometer from that timestamp and a server-time anchor; they do not increment an independent stopwatch. Timestamps are serialized in UTC with an explicit timezone marker, so the clock starts at zero in every browser timezone. A browser reload does not reset the clock. After finishing, the clock displays the fixed difference between `finished_at` and `started_at`.

The normal race-day flow uses an unguessable share link. Anyone with that link can open the race without an account, choose an active checkpoint or finish, and tap the correct athlete to record the current race time. The backend validates race/checkpoint/entry relationships and warns when required earlier checkpoints are missing. Authenticated timing stations remain available as an optional advanced mode for named Officials and offline recovery.

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

The shared race-day link is the simplest online timing flow. For unreliable connections, use the optional authenticated full-screen timing station: failed timing submissions are saved to IndexedDB with their UUID and observed timestamp, then retried after reconnection. The UUID makes retries idempotent.

When using those offline-capable stations, open each station on its intended device while connectivity is good before race day. Do not clear browser site data during the event.

## Results

Results are derived from active timing records. The app shows cumulative checkpoint time and per-checkpoint split time, and exports CSV/XLSX files.

## Tests and quality

```bash
php artisan test
yarn typecheck
yarn build

# Optional source formatting
./vendor/bin/pint
```

Pull requests run the test suite, TypeScript checks, and production frontend build in `.github/workflows/quality.yml`. Run Laravel Pint locally when changing PHP files. The `main` branch workflow is intentionally deployment-only; Laravel Forge should be configured to deploy after `main` changes.

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
3. Open or copy the race-day link and share it with the people timing the race.
4. On each device, open that link and select the correct transition/checkpoint or finish.
5. Start the shared race clock when the race begins.
6. Tap/search the correct athlete as they cross the selected timing point.
7. The race finishes automatically when the last active athlete receives a finish time, or the Organizer can end it manually.
8. Review and export/publish results.

For poor-connectivity events, optionally create named Official accounts and use the authenticated full-screen timing station with its offline queue and recovery tools.

## Navigation and account management

The primary Organizer workflow lives in one Race workspace: course, athletes, race-day link, race clock, timing and results. The separate participant list, full-screen timing station and race-control pages remain available as secondary tools for bulk editing, offline recovery, corrections and station health.

Use People only when you need optional named Official or Athlete accounts. Official permissions and checkpoint assignments apply to those account-based stations; athlete access is scoped to the linked athlete profile. At least one Organizer (admin) account must remain active. Disabling an account also blocks its existing sessions on their next request.

Deleting a race is reversible from the Deleted races section on the race list. No participant, checkpoint or timing history is purged. Deleted races are hidden from organizers and athletes until an administrator restores them.
