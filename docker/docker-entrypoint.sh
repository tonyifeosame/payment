#!/bin/sh
set -e

# Runtime preparation for every container built from this image: the web
# service, the queue worker and the reconciliation cron.
#
# Render's dockerCommand replaces the image's ENTRYPOINT as well as its CMD, so
# this script does NOT run on its own there. Every service in render.yaml
# therefore starts as `/docker-entrypoint.sh <command>`. Before that, Render ran
# its commands directly: no .env, no runtime config:cache and no migration, and
# Laravel read the config cache baked in at build time -- APP_KEY null, APP_URL
# http://localhost, sqlite, the log mailer.
#
# The config cache is built HERE, at runtime, once Render's environment exists.
# The image never contains one (docker/Dockerfile).

# Already prepared in this container: the script was both the ENTRYPOINT and
# the first argument (a platform that does run the ENTRYPOINT, or a local
# `docker run IMAGE /docker-entrypoint.sh ...`). Run the command only.
if [ "${FEYRA_RUNTIME_PREPARED:-}" = "1" ]; then
  exec "$@"
fi

# /app in the image; overridable only so the test suite can run this script.
app_root=${FEYRA_APP_ROOT:-/app}
cd "$app_root"

# .env holds only the .env.example defaults the environment does not override;
# real values stay in the environment (see docker/write-dotenv.sh).
. "$app_root/docker/write-dotenv.sh"
write_dotenv .env.example .env

# Refuse to cache a production config without its two required values, rather
# than start with a null key (MissingAppKeyException on every request) or
# localhost links in every receipt. Checked against the environment itself:
# .env.example's defaults are not acceptable values for either.
if [ "${APP_ENV:-}" = "production" ]; then
  if [ -z "${APP_KEY:-}" ]; then
    echo "docker-entrypoint: APP_KEY is not set. Set the same key on all three Render services." >&2
    exit 1
  fi
  case "${APP_URL:-}" in
    https://?*) ;;
    *)
      echo "docker-entrypoint: APP_URL must be the public https:// address (https://feyra.site), set on all three Render services." >&2
      exit 1
      ;;
  esac
fi

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

export FEYRA_RUNTIME_PREPARED=1
exec "$@"
