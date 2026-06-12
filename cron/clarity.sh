#!/bin/sh
# Denný Clarity snapshot — volané z Hostinger cronu.
# hPanel k príkazu prilepuje argument navyše (/usr/bin/php) — skript ho ignoruje.

DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG="$DIR/storage/logs/clarity-cron.log"

cd "$DIR" || exit 1

{
    echo "=== $(date '+%Y-%m-%d %H:%M:%S') ==="
    php -v | head -1
    php artisan analytics:clarity-snapshot
} >> "$LOG" 2>&1
