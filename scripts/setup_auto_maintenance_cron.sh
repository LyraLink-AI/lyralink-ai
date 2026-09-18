#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="/var/www/vhosts/lyralinkai.com/httpdocs"
PHP_BIN="$(command -v php || true)"

if [[ -z "$PHP_BIN" ]]; then
  echo "php not found in PATH"
  exit 1
fi

CRON_LINE="0 */4 * * * cd ${ROOT_DIR} && ${PHP_BIN} ${ROOT_DIR}/cron/auto_maintenance_runner.php >> /tmp/lyralink-auto-maintenance.log 2>&1"

TMP_CRON="$(mktemp)"
crontab -l 2>/dev/null | grep -v 'auto_maintenance_runner.php' > "$TMP_CRON" || true
echo "$CRON_LINE" >> "$TMP_CRON"
crontab "$TMP_CRON"
rm -f "$TMP_CRON"

echo "Installed auto-maintenance cron job:"
echo "$CRON_LINE"
