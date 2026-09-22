<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue / payout health thresholds
    |--------------------------------------------------------------------------
    |
    | Read by `php artisan jobs:check` (App\Console\Commands\QueueHealthCheck),
    | which the hourly Render cron runs after the reconciliation passes. Each
    | threshold is the count at which the system is reported UNHEALTHY; the
    | command exits non-zero and (unless --no-mail) emails the operator.
    |
    | Defaults are deliberately tight, because none of these states is normal:
    | a failed job means work was abandoned, and a payout the hourly cron will
    | not re-queue on its own needs a human. `env()` is read here rather than in
    | the command because the image runs `config:cache` (docker-entrypoint.sh).
    |
    */

    'queue_health' => [

        // Rows in `failed_jobs`. Any is worth knowing about.
        'failed_jobs' => (int) env('HEALTH_FAILED_JOBS_THRESHOLD', 1),

        // Rows in `jobs`. A handful in flight is normal; a pile means the worker
        // is not draining. Sized well above an ordinary burst of payouts.
        'queue_depth' => (int) env('HEALTH_QUEUE_DEPTH_THRESHOLD', 100),

        // Payouts left `pending` after at least one attempt. `payouts:run
        // --dispatch` only re-queues obligations that were NEVER queued
        // (attempts = 0), so these need `payouts:retry` from an operator —
        // nothing picks them up automatically.
        'stalled_payouts' => (int) env('HEALTH_STALLED_PAYOUTS_THRESHOLD', 1),

        // Payouts in `failed` or `needs_review`: both are deliberate dead ends
        // that only an operator command can move.
        'attention_payouts' => (int) env('HEALTH_ATTENTION_PAYOUTS_THRESHOLD', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator alerts
    |--------------------------------------------------------------------------
    |
    | Where an unhealthy `jobs:check` sends its notice, and how often it may
    | repeat while the same problem persists. Mail is the primary signal because
    | it is the one delivery path this application already proves in production
    | (the contact form delivers to the same address). The command's non-zero
    | exit is the secondary signal, for Render's own cron-failure notification
    | where a workspace has it switched on.
    |
    | A null address disables the mail (the exit code still stands).
    |
    */

    'alerts' => [
        'to' => env('OPERATIONS_ALERT_EMAIL') ?: env('MAIL_FROM_ADDRESS'),

        // Minutes before the same unhealthy state may mail again. The cron runs
        // hourly; six hours keeps a persistent problem visible without filling
        // the inbox. Counted in the shared cache store, so every container
        // agrees. 0 disables the cooldown.
        'cooldown_minutes' => (int) env('OPERATIONS_ALERT_COOLDOWN_MINUTES', 360),
    ],

];
