#!/usr/bin/env bash
set -euo pipefail

cd "$FORGE_SITE_PATH"

git pull origin main
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

php artisan migrate --force
php artisan optimize:clear

corepack enable
yarn install --frozen-lockfile --non-interactive
yarn build

php artisan optimize
php artisan queue:restart || true
php artisan reverb:restart || true
