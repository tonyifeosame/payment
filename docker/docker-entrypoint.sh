#!/bin/sh
set -e

cd /app  # ensure we're in Laravel root

# Build .env from .env.example + current env
while IFS= read -r line; do
  # Skip blank lines & comments
  case "$line" in
    ''|\#*) continue ;;
  esac

  var=${line%%=*}     # before first '='
  def=${line#*=}      # after first '='

  case "$var" in
    RENDER_*|KUBERNETES_*|HOSTNAME|PATH) continue ;;
  esac

  # If APP_KEY is in .env.example, skip it and let Laravel generate it
  if [ "$var" = "APP_KEY" ]; then
    continue
  fi

  val=$(eval "echo \${$var}")
  [ -n "$val" ] || val=$def

  printf '%s=%s\n' "$var" "$val"
done < .env.example > .env

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
