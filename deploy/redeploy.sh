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

# `set -e` aborts on the first failing command, which is right — a half
# deploy must not reload — but on its own it exits with nothing but the
# tool's own error scrolled off the top, and the site keeps serving the
# old code with no sign anything went wrong. That is indistinguishable
# from "the deploy worked but the feature is broken", and it cost a
# round trip (2026-09-13: the panel bundle and the API routes were both
# a commit behind and the deploy looked like it had succeeded).
CURRENT="startup"
step() { CURRENT="$1"; echo "==> $1"; }

on_failure() {
    echo
    echo "!! DEPLOY FAILED during: ${CURRENT}"
    echo "!! The error is above. Nothing was reloaded, so the site is STILL"
    echo "!! running the previous version — it is not half-updated."

    case "$CURRENT" in
        "git pull")
            echo "!! Usually a dirty working tree on the server. Check with:"
            echo "!!     git -C \"\$PWD\" status --short"
            ;;
        "npm build (panel)")
            echo "!! Usually the Vite build running out of memory on a small VPS"
            echo "!! (the process is killed with no message). Check with:"
            echo "!!     free -m ; dmesg | tail -5"
            echo "!! If so, add swap or build with:"
            echo "!!     NODE_OPTIONS=--max-old-space-size=512 npm run build"
            ;;
    esac

    echo "!! Fix it, then re-run ./deploy/redeploy.sh — it is safe to repeat."
    exit 1
}

trap on_failure ERR

was="$(git rev-parse --short HEAD)"

step "git pull"
git pull --ff-only origin main
now="$(git rev-parse --short HEAD)"
echo "    ${was} -> ${now}"

step "composer install (api)"
cd api
composer install --no-dev --optimize-autoloader
cd ..

step "npm build (panel)"
cd panel
npm ci
npm run build
cd ..

step "migrations"
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
step "reloading PHP"

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

trap - ERR

# What the browser should now be loading. When a change is "deployed"
# but not visible, comparing this filename against the <script src> in
# the page's view-source settles in one look whether the build reached
# the web root or the browser is holding a cached index.html.
echo "==> done. Now live: ${now}"

bundle="$(ls -1 panel/dist/assets/index-*.js 2>/dev/null | head -1 || true)"

if [ -n "$bundle" ]; then
    echo "    panel bundle: $(basename "$bundle")"
    echo "    (view-source on the panel should reference this exact file;"
    echo "     if it names a different one, the browser cached the old page"
    echo "     — reload with Ctrl+Shift+R.)"
fi
