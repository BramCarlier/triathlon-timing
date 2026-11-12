#!/usr/bin/env bash
# Nightly off-server MySQL backup for a Laravel site on Forge.
#
# Works for any site on the server (Triathlon Timing, Brotherhood Manager): it
# reads the DB_* values from the site's .env, writes a compressed, consistent
# dump, uploads it with rclone and prunes old copies locally and remotely.
#
# Usage (Forge → Server → Scheduler, daily, as the site's user):
#   bash /home/USER/SITE/current/deploy/backup-database.sh /home/USER/SITE/current
#
# Environment (optional):
#   BACKUP_REMOTE   rclone destination, e.g. "b2:club-backups/triathlon-timing".
#                   Configure it once with `rclone config` as the same user.
#                   Without it the dump stays on the server only (not off-server!).
#   BACKUP_DIR      local folder for dumps (default: ~/backups/<database>)
#   BACKUP_KEEP_DAYS  days to keep (default: 30)
#   BACKUP_PING_URL   optional URL to request after success (uptime monitor heartbeat)
set -euo pipefail

app_dir="${1:?Pass the application directory (the one containing .env)}"
env_file="$app_dir/.env"
[[ -r "$env_file" ]] || { echo "Cannot read $env_file" >&2; exit 1; }

# Read one KEY=value from .env without evaluating it as shell code.
env_value() {
  local line
  line="$(grep -E "^$1=" "$env_file" | tail -n 1 || true)"
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

db_connection="$(env_value DB_CONNECTION)"
[[ "$db_connection" == "mysql" || "$db_connection" == "mariadb" ]] || { echo "Only MySQL/MariaDB is supported (DB_CONNECTION=$db_connection)." >&2; exit 1; }

db_host="$(env_value DB_HOST)"; db_host="${db_host:-127.0.0.1}"
db_port="$(env_value DB_PORT)"; db_port="${db_port:-3306}"
db_name="$(env_value DB_DATABASE)"
db_user="$(env_value DB_USERNAME)"
db_pass="$(env_value DB_PASSWORD)"
[[ -n "$db_name" && -n "$db_user" ]] || { echo "DB_DATABASE and DB_USERNAME must be set." >&2; exit 1; }

keep_days="${BACKUP_KEEP_DAYS:-30}"
backup_dir="${BACKUP_DIR:-$HOME/backups/$db_name}"
mkdir -p "$backup_dir"
chmod 700 "$backup_dir"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$backup_dir/$db_name-$stamp.sql.gz"
partial="$target.partial"

# Credentials go through a private option file, never the process list.
credentials="$(mktemp)"
trap 'rm -f "$credentials" "$partial"' EXIT
chmod 600 "$credentials"
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$db_host" "$db_port" "$db_user" "$db_pass" > "$credentials"

mysqldump --defaults-extra-file="$credentials" \
  --single-transaction --quick --routines --triggers --events \
  --no-tablespaces --set-gtid-purged=OFF \
  "$db_name" | gzip -9 > "$partial"
gzip -t "$partial"
mv "$partial" "$target"
echo "Backup written: $target ($(du -h "$target" | cut -f1))"

if [[ -n "${BACKUP_REMOTE:-}" ]]; then
  command -v rclone >/dev/null || { echo "BACKUP_REMOTE is set but rclone is not installed." >&2; exit 1; }
  rclone copy --no-traverse "$target" "$BACKUP_REMOTE/"
  rclone delete --min-age "${keep_days}d" "$BACKUP_REMOTE/"
  echo "Uploaded to $BACKUP_REMOTE"
else
  echo "WARNING: BACKUP_REMOTE is not set; this backup is only on this server." >&2
fi

find "$backup_dir" -name "$db_name-*.sql.gz" -mtime +"$keep_days" -delete

if [[ -n "${BACKUP_PING_URL:-}" ]]; then
  curl -fsS -m 10 --retry 3 "$BACKUP_PING_URL" >/dev/null || echo "Heartbeat ping failed." >&2
fi
