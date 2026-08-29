#!/usr/bin/env bash
# Pulls the latest main, rebuilds the panel, refreshes PHP deps, runs any
# new migrations, and RELOADS PHP so the new code actually takes effect.
# Safe to re-run: composer/npm/migrate are all idempotent, and this never
# touches api/.env or storage/.
#
# Usage (from the deploy user, inside the cloned repo):
#   ./deploy/redeploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> git pull"
git pull --ff-only origin main

echo "==> composer install (api)"
cd api
composer install --no-dev --optimize-autoloader
cd ..

echo "==> npm build (panel)"
cd panel
npm ci
npm run build
cd ..

echo "==> migrations"
cd api
php bin/migrate.php
cd ..

# ---------------------------------------------------------------- reload
#
# NOT optional, and the reason this script exists in this shape: the
# production php.ini sets `opcache.validate_timestamps = 0`, so PHP
# compiles each file once and never looks at the mtime again. Without a
# reload the git pull above changes the files on disk while every request
# keeps running the OLD code — silently, with no error anywhere. That
# cost a real debugging session (2026-08-28: an importer fix was pulled,
# the import re-run, and the new column ignored because the old importer
# was still resident).
#
# Whichever stack is installed gets reloaded; php-fpm is what holds the
# opcache under nginx, Apache holds it under mod_php.
echo "==> reloading PHP"

reloaded=0

for unit in php8.2-fpm php8.3-fpm php-fpm; do
    if systemctl list-units --type=service --all --no-legend 2>/dev/null | grep -q "^${unit}\.service"; then
        sudo systemctl reload "$unit" && echo "    reloaded ${unit}" && reloaded=1
    fi
done

for unit in nginx apache2 httpd; do
    if systemctl list-units --type=service --all --no-legend 2>/dev/null | grep -q "^${unit}\.service"; then
        sudo systemctl reload "$unit" && echo "    reloaded ${unit}" && reloaded=1
    fi
done

if [ "$reloaded" -eq 0 ]; then
    echo "    WARNING: no php-fpm/nginx/apache service found to reload."
    echo "    If the site runs PHP with opcache, the new code is NOT live yet."
    exit 1
fi

echo "==> done."
