# Laravel Forge production setup

## Site and runtime

Create a Laravel site for `BramCarlier/triathlon-timing`, branch `main`, with PHP 8.4 and web directory `/public`. Use Node.js **24 LTS** and the Yarn version pinned in `package.json` (currently 4.18.0). Corepack must be installed. Yarn 1's `--non-interactive` fails with Yarn 4; use `--immutable`.

Keep Forge's default zero-downtime deployment enabled. Add `storage` to shared paths; `.env` is shared automatically. Commands and background processes must use `/home/forge/YOUR_SITE/current`, not a numbered release. Disable initial automatic deployment until environment and deployment settings are ready. Enable push-to-deploy for `main` after the first successful deployment.

Use an `on-forge.com` domain or point a custom domain's DNS to the server. Install/verify SSL and redirect HTTP to HTTPS before testing authentication and WebSockets.

Create a separate MySQL 8+ database and a database user restricted to that database. PostgreSQL is also supported; use `DB_CONNECTION=pgsql`, port 5432 and `pdo_pgsql`. PHP needs the extensions required by `composer.lock`, including `mbstring`, `dom`, `xml`, `fileinfo`, `curl`, `zip`, `gd`, `intl` and `pdo_mysql` for MySQL. Do not use `--ignore-platform-reqs`.

## Environment and first installation

Start with `.env.example`, keeping credentials in Forge's environment editor. Replace all placeholders below. Keep stored race times in UTC.

```dotenv
APP_NAME="Triathlon Timing"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR_SITE
APP_TIMEZONE=UTC
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=triathlon_timing
DB_USERNAME=triathlon_timing
DB_PASSWORD="YOUR_DATABASE_PASSWORD"

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=YOUR_RANDOM_APP_ID
REVERB_APP_KEY=YOUR_RANDOM_APP_KEY
REVERB_APP_SECRET=YOUR_RANDOM_APP_SECRET
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGIN=YOUR_SITE
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=YOUR_SITE
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

`YOUR_SITE` means the hostname only. Set `REVERB_ALLOWED_ORIGIN` explicitly: leaving `localhost` rejects production browser connections. Backend broadcasts use loopback HTTP; browsers use public HTTPS. If port 8080 is occupied, choose a free loopback port and update the daemon, server variables, broadcast variables and Nginx upstream together.

Set `APP_KEY` once with `php8.4 artisan key:generate` after Composer installation, or through Forge's Laravel setup. Preserve it across deployments. Generate fresh cryptographically random Reverb credentials; never use `local-key` or `local-secret` in production. Never expose the Reverb secret through a `VITE_` variable.

Configure a real SMTP provider for password-reset delivery:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=YOUR_SMTP_HOST
MAIL_PORT=587
MAIL_USERNAME=YOUR_SMTP_USERNAME
MAIL_PASSWORD="YOUR_SMTP_PASSWORD"
MAIL_FROM_ADDRESS="YOUR_VERIFIED_SENDER"
MAIL_FROM_NAME="${APP_NAME}"
```

Follow the provider's TLS/port settings (implicit TLS uses `MAIL_SCHEME=smtps`, usually port 465). `MAIL_MAILER=log` does not deliver emails. Rebuild assets whenever any `VITE_` value changes.

## Reverb

After initial deployment, create a supervised Forge background process as the site's Unix user, working directory `/home/forge/YOUR_SITE/current`:

```bash
php8.4 /home/forge/YOUR_SITE/current/artisan reverb:start --host=127.0.0.1 --port=8080
```

Enable automatic restart. Keep the Reverb port private. In the site's Nginx **server** block, proxy the WebSocket endpoint to the loopback daemon, preserving the rest of Forge's configuration:

```nginx
location ^~ /app/ {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
    proxy_pass http://127.0.0.1:8080;
}
```

Validate Nginx configuration before reloading. With the loopback broadcast settings above, Laravel sends `/apps` API requests directly to Reverb; they need no public proxy. If Forge's Reverb integration generates the proxy, inspect and use it instead of adding a duplicate location.

## Queue worker and scheduler

Create one supervised queue worker as the site's Unix user, working directory `/home/forge/YOUR_SITE/current`:

```bash
php8.4 /home/forge/YOUR_SITE/current/artisan queue:work database --sleep=1 --tries=3 --timeout=60
```

The 60-second timeout must stay shorter than the database queue's 90-second `retry_after`, preventing simultaneous retries. Enable automatic restart and allow at least 90 seconds for graceful shutdown.

Create exactly one scheduler entry for this site, every minute as the site's Unix user:

```bash
php8.4 /home/forge/YOUR_SITE/current/artisan schedule:run
```

The current `routes/console.php` defines no recurring business tasks; the scheduler can therefore report no commands as due.

## Deployment

`deploy/forge-deploy.sh` builds assets, installs production Composer dependencies, clears stale configuration, migrates, and caches the application in the **provided release directory**. Forge's outer script owns checkout, activation and process restarts. Do not hide failures with `|| true`.

For zero-downtime sites, use this in Forge's deployment editor. Retain the literal Forge macros: Forge expands them before Bash execution.

```bash
set -euo pipefail

$CREATE_RELEASE()
cd "$FORGE_RELEASE_DIRECTORY"
bash deploy/forge-deploy.sh "$FORGE_RELEASE_DIRECTORY"

$ACTIVATE_RELEASE()
cd "$FORGE_SITE_PATH"
$FORGE_PHP artisan queue:restart
$FORGE_PHP artisan reverb:restart
```

Restart **after** activation so supervised processes start the new code from `current`. This uses an explicit queue restart instead of an additional `$RESTART_QUEUES()` call. Configure both processes to restart on exit. Zero-downtime releases do not require a PHP-FPM reload.

For existing standard (in-place) sites, use this wrapper instead:

```bash
set -euo pipefail
cd "$FORGE_SITE_PATH"
git pull --ff-only origin "$FORGE_SITE_BRANCH"
bash deploy/forge-deploy.sh "$FORGE_SITE_PATH"
sudo -S service "$FORGE_PHP_FPM" reload
$FORGE_PHP artisan queue:restart
$FORGE_PHP artisan reverb:restart
```

Never regenerate `APP_KEY`, recreate the database, run `migrate:fresh`, or seed demo data in deployment. Take backups before schema-changing releases; rolling back code does not undo migrations.

## First administrator

After migrations, open an interactive terminal:

```bash
cd /home/forge/YOUR_SITE/current
php8.4 artisan app:create-admin admin@example.com --name="Race Director"
```

Use the owner's real email. The command prompts without echo for a password of at least 12 characters. Do not put passwords in a deploy script, command-line argument or commit. The command also resets/promotes an existing matching account; for initial setup use a new account. Sign in over HTTPS and verify administrator access.

## Release verification

1. Confirm deployment commit, successful `php8.4 artisan migrate:status`, HTTP 200 at `/up`, HTTPS redirect and frontend assets.
2. Test administrator login, organizer creation/assignment, athlete login and role restrictions.
3. Create a clearly marked test race. Import JSON and XLSX/CSV examples, check preview, bibs and relay membership.
4. Open two checkpoint sessions, select different stations, start the race, record splits and verify live updates and shared clock behavior on reload.
5. Test invalid/duplicate taps, undo/re-record, offline queue replay, results and export. Keep test records separate from real events.
6. Verify the WebSocket connection, running Reverb/queue processes, scheduler entry and an actual password-reset email before declaring readiness.

Open checkpoint devices while connectivity is good before race day. Do not clear site data or switch browsers while unsynchronized timings remain.

## References

- [Forge deployment strategies, variables and restarts](https://laravel.com/forge/docs/sites/deployments)
- [Reverb production configuration](https://laravel.com/framework/docs/13.x/reverb)
- [Queue timeouts](https://laravel.com/framework/docs/13.x/queues#job-expirations-and-timeouts)
- [Yarn immutable installs](https://yarnpkg.com/cli/install)
