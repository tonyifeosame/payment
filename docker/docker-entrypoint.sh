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
php artisan migrate --force
php artisan optimize

exec "$@"
