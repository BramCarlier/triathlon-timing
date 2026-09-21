# Laravel Forge production setup

## Site

Use PHP 8.4, point the web root at `/public`, and create a MySQL 8+ or PostgreSQL database. Copy `.env.example` to `.env`, set `APP_ENV=production`, `APP_DEBUG=false`, the production `APP_URL`, database credentials, and mail settings. Run `php artisan key:generate` once.

## Reverb

Use `BROADCAST_CONNECTION=reverb`. Configure `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` to strong random values. For the browser values set `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST` to the site's hostname, `VITE_REVERB_PORT=443`, and `VITE_REVERB_SCHEME=https`.

Create a Forge daemon for Reverb:

```bash
php /home/forge/YOUR_SITE/artisan reverb:start --host=127.0.0.1 --port=8080
```

Proxy `/app` WebSocket traffic to Reverb according to Forge's Reverb/WebSocket configuration. TLS terminates at Nginx.

## Queue worker

Create a Forge daemon:

```bash
php /home/forge/YOUR_SITE/artisan queue:work --sleep=1 --tries=3 --timeout=90
```

## Scheduler

Enable the standard Forge scheduler entry:

```bash
php /home/forge/YOUR_SITE/artisan schedule:run
```

once per minute.

## First admin

After migrations:

```bash
php artisan app:create-admin admin@example.com --name="Race Director"
```

The command asks for the password without echoing it.

## Deploy script

Use the contents of `deploy/forge-deploy.sh`, or export `FORGE_SITE_PATH=/home/forge/YOUR_SITE` before invoking that script.

## Race-day recommendation

Open every checkpoint device while connectivity is good before the race. The checkpoint page can keep failed timing submissions in IndexedDB and synchronize them after connectivity returns. Operators should avoid clearing site data or switching browsers during a race.
