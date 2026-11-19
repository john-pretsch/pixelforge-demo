#!/usr/bin/env bash
# Starts the isolated E2E stack for Playwright (see playwright.config.ts):
# APP_ENV=e2e loads .env.e2e (own database, dev Pixelforge key), the prod
# config/route caches are bypassed, and everything runs as www-data so no
# root-owned files land in the shared storage/ directory.
set -euo pipefail
cd "$(dirname "$0")/../.."

if [ "$(id -u)" = 0 ]; then
    exec sudo -u www-data bash "$0"
fi

export APP_ENV=e2e \
    APP_CONFIG_CACHE=bootstrap/cache/config.e2e.php \
    APP_ROUTES_CACHE=bootstrap/cache/routes.e2e.php \
    APP_EVENTS_CACHE=bootstrap/cache/events.e2e.php \
    PHP_CLI_SERVER_WORKERS=4

[ -r .env.e2e ] || { echo "Missing .env.e2e (copy .env.e2e.example)"; exit 1; }
grep -q '^PIXELFORGE_API_KEY=pf_' .env.e2e || { echo "Set a DEV PIXELFORGE_API_KEY in .env.e2e"; exit 1; }
[ ! -e public/hot ] || { echo "public/hot exists: stop 'npm run dev' (E2E uses built assets)"; exit 1; }
[ -f public/build/manifest.json ] || { echo "Run 'npm run build' first"; exit 1; }

# Fresh database and a known, verified user with $10 of credits (routes require 'verified').
php artisan migrate:fresh --force -q
php artisan tinker --execute '\App\Models\User::factory()->withCredits("10.00")->create(["name" => "E2E", "email" => "e2e@example.test", "password" => "e2e-password"]);'

trap 'kill 0' EXIT

# Worker + polling fallback every few seconds (prod relies on cron each minute;
# webhooks can't reach this local stack).
(
    while true; do
        php artisan queue:work --stop-when-empty --tries=0 -q || true
        php artisan pixelforge:reconcile --stale=0 -q || true
        sleep 2
    done
) &

php artisan serve --host=127.0.0.1 --port=8001 --no-reload
