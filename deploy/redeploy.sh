#!/usr/bin/env bash
# Pulls the latest main, rebuilds the panel, refreshes PHP deps, and runs
# any new migrations. Safe to re-run: composer/npm/migrate are all
# idempotent, and this never touches api/.env or storage/.
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

echo "==> done. Reload the web server if you changed php.ini or the vhost:"
echo "      sudo systemctl reload apache2"
