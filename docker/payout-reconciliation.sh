#!/bin/sh
# The hourly laravel-payout-reconciliation cron (render.yaml), run as
# `/docker-entrypoint.sh sh /app/docker/payout-reconciliation.sh`.
#
# A file rather than an inline `sh -c '...'`: Render splits dockerCommand into
# arguments without removing shell quotes, so the quoted script reached sh as a
# single word and failed with "not found" (exit 127).
#
# All three passes always run; the exit status is the first non-zero one, so a
# failure in any pass is visible in Render without hiding the others.
php artisan payouts:run --dispatch; a=$?
php artisan payments:expire-pending; b=$?
php artisan jobs:check; c=$?
if [ "$a" -ne 0 ]; then exit "$a"; fi
if [ "$b" -ne 0 ]; then exit "$b"; fi
exit "$c"
