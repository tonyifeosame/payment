#!/bin/sh
set -e

cd /app  # ensure we're in Laravel root

# .env holds only the .env.example defaults the environment does not override;
# real values stay in the environment (see docker/write-dotenv.sh).
. /app/docker/write-dotenv.sh
write_dotenv .env.example .env

php artisan config:clear
php artisan config:cache

# The same image runs the web service, the queue worker and the reconciliation
# cron. Only one of them may migrate: Render starts Blueprint services in no
# particular order, and three containers running `migrate --force` against one
# Postgres can half-apply a migration to the payment ledger. SKIP_MIGRATIONS is
# set on the worker and the cron; unset (the local `docker run` case) still
# migrates, so nothing about local usage changes.
if [ "${SKIP_MIGRATIONS:-false}" = "true" ]; then
  echo "SKIP_MIGRATIONS=true: leaving migrations to the web service."
else
  php artisan migrate --force
fi

php artisan optimize

exec "$@"
