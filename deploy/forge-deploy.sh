#!/usr/bin/env bash
set -euo pipefail

# Build the selected release. Forge's outer script owns checkout, activation,
# PHP-FPM reloads and process restarts; see FORGE.md for both deployment modes.
cd "${1:-${FORGE_SITE_PATH:?Pass the release path or set FORGE_SITE_PATH}}"

timing_php="${FORGE_PHP:-php8.4}"
# Forge may supply either a Composer path or a command such as
# "php8.4 /usr/local/bin/composer". Keep its arguments separate, without eval.
read -r -a timing_composer <<< "${FORGE_COMPOSER:-composer}"
if (( ${#timing_composer[@]} == 1 )); then
    timing_composer=("$timing_php" "$(command -v "${timing_composer[0]}")")
fi

node -e 'if (process.versions.node.split(".")[0] !== "24") { console.error("Deployment requires Node.js 24 LTS."); process.exit(1); }'
"$timing_php" -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) { fwrite(STDERR, "Deployment requires PHP 8.4.\n"); exit(1); }'

# Corepack reads packageManager from package.json without changing server-wide
# shims. Keep development dependencies available for the Vite build.
corepack yarn install --immutable
corepack yarn build
"${timing_composer[@]}" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Clear only cached configuration before migrations: optimize:clear also clears
# the database cache, whose table does not exist on the initial deployment.
"$timing_php" artisan config:clear
"$timing_php" artisan timing:check-reverb
"$timing_php" artisan migrate --force
"$timing_php" artisan optimize
