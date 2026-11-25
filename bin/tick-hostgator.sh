#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
php_cli=${ORFEU_PHP:-php}
mkdir -p storage/logs
file=storage/logs/cron.log
if [ -f "$file" ] && [ "$(wc -c < "$file")" -gt 1048576 ]; then
    mv -f "$file" "$file.1"
fi
"$php_cli" bin/orfeu.php tick --once >> "$file" 2>&1
