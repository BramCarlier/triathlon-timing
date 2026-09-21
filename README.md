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

The race has one authoritative `started_at` timestamp stored by the server. Browsers derive the displayed chronometer from that timestamp and a server-time anchor; they do not increment an independent stopwatch. A browser reload therefore does not reset the race clock.

Each organizer chooses a checkpoint for the current browser session. Every participant tap is then recorded against that checkpoint automatically. The backend validates race/checkpoint/entry relationships and warns when required earlier checkpoints are missing.

### Default checkpoint order

A new race gets:

1. Race Start
2. Swim Finish
3. Bike Finish
4. Finish

Admins/organizers can add arbitrary extra splits such as `SWIM_500M`, `BIKE_10K`, `RUN_4K`, etc. Checkpoint sequence numbers determine progression.

## Participant model

An **entry** owns the bib number. An entry is either:

- `solo`: one athlete is linked to swim, bike and run.
- `relay`: one swim athlete, one bike athlete and one run athlete.

This means timing is always attached to the race entry while the app can still attribute a discipline split to the correct relay athlete.

## Accounts and roles

### Administrator

- create/configure races
- create organizer and athlete accounts
- assign organizers to races
- import/manage participants
- start/finish race clocks
- use any checkpoint station
- void/re-record timings
- export results

### Organizer

- sees assigned races
- selects their own checkpoint for the current session/device
- records participants with one tap
- sees recent taps and can undo their own timings
- imports/manages participants and race setup for assigned races
- sees race control and results

### Athlete

- signs in with an account linked to an athlete profile
- can request a password-reset email if they forget their password
- sees their bib/team, teammates, live race clock and recorded splits
- can view race results

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

The three relay rows share the same bib. `discipline` must be `swim`, `bike`, or `run`.

A wide relay spreadsheet is also accepted when a single row contains fields such as `swim_first_name`, `swim_last_name`, `bike_first_name`, and `run_first_name`.

Examples are in `examples/` and a CSV template is downloadable inside the application.

## Timing safeguards

Every timing record has:

- a browser-generated UUID for idempotency
- race, entry and checkpoint IDs
- discipline athlete attribution where applicable
- operator ID
- exact UTC `recorded_at`
- integer elapsed milliseconds
- source (`online`, `offline`, `manual`)
- warning acknowledgement state
- void audit data

The service prevents duplicate active timings at the same checkpoint. Voiding a timing retains the original audit row and allows a new timing to be recorded.

## Offline behavior

While the checkpoint page is open, a failed timing submission is saved to IndexedDB with its UUID and observed timestamp. On reconnection the page retries queued records. The UUID makes retries idempotent.

Before race day, open every station on its intended device while connectivity is good. Do not clear browser site data during the event.

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

1. Import participants and resolve import warnings.
2. Verify bib numbers and relay disciplines.
3. Configure every checkpoint in correct sequence.
4. Assign organizer accounts to the race.
5. Sign in on each checkpoint device and select its station.
6. Confirm every device appears in Race Control with `Synced` status.
7. Start the race from Race Control once the starter gives the signal.
8. Operators tap/search bibs at their own checkpoint only.
9. Use recent-tap Undo immediately for mistakes.
10. Watch Race Control for offline devices and queued timings.
11. Finish the race only after the final participants have completed.
12. Review/export results.
