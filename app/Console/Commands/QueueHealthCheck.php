<?php

namespace App\Console\Commands;

use App\Mail\QueueHealthAlertMail;
use App\Models\Payout;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * M5 — the one place that says out loud whether queued work is healthy.
 *
 * The recovery layer built by B1/H1/H5 is good at *fixing* things: an obligation
 * that was never queued is re-dispatched hourly, an ambiguous transfer is
 * reconciled by lookup, a stale checkout is verified with Paystack. What it
 * never did was tell anyone when something fell outside that layer, and two
 * states do:
 *
 *   - a payout left `pending` after at least one attempt. `payouts:run
 *     --dispatch` re-queues only obligations that were NEVER queued
 *     (attempts = 0), so these sit until an operator runs `payouts:retry`;
 *   - a payout in `failed` or `needs_review`, both deliberate dead ends.
 *
 * Plus the queue itself: rows in `failed_jobs` (work abandoned after its
 * retries) and a `jobs` backlog that is not draining.
 *
 * Strictly read-only. It runs on the cron container, which carries
 * SKIP_MIGRATIONS=true and must never write to the ledger.
 *
 *   php artisan jobs:check              human-readable report
 *   php artisan jobs:check --json       machine-readable
 *   php artisan jobs:check --no-mail    report and exit code only
 *
 * Exit code is 0 when healthy and 1 when any threshold is met, so Render marks
 * the cron run failed. That signal is secondary: cron-failure notifications are
 * a dashboard setting we cannot assert is switched on, so the mail below is the
 * primary one. See docs/production/paystack-runbook.md.
 */
class QueueHealthCheck extends Command
{
    protected $signature = 'jobs:check
                            {--json : Emit the report as JSON}
                            {--no-mail : Do not email the operator even if unhealthy}';

    protected $description = 'Report failed jobs, queue depth and payouts that need an operator (read-only)';

    /** Cache key for the alert cooldown; shared by every container. */
    private const COOLDOWN_KEY = 'operations:queue-health:last-alert';

    public function handle(): int
    {
        $report = $this->buildReport();

        $this->option('json')
            ? $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            : $this->render($report);

        if (! $report['healthy']) {
            Log::warning('Queue health check reported problems', $report['problems']);

            if (! $this->option('no-mail')) {
                $this->notify($report);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{healthy:bool, checked_at:string, metrics:array<string,mixed>, problems:array<string,string>}
     */
    private function buildReport(): array
    {
        $thresholds = (array) config('operations.queue_health');

        $failedTable = (string) config('queue.failed.table', 'failed_jobs');
        $jobsTable = (string) config('queue.connections.database.table', 'jobs');

        $failedJobs = (int) DB::table($failedTable)->count();
        $oldestFailedAt = $failedJobs > 0 ? DB::table($failedTable)->min('failed_at') : null;

        $queueDepth = (int) DB::table($jobsTable)->count();

        // Left pending after an attempt: nothing re-queues these automatically.
        $stalledPayouts = (int) Payout::where('status', Payout::PENDING)->where('attempts', '>=', 1)->count();
        $attentionPayouts = (int) Payout::whereIn('status', [Payout::FAILED, Payout::NEEDS_REVIEW])->count();

        $metrics = [
            'failed_jobs' => $failedJobs,
            'oldest_failed_job_at' => $oldestFailedAt ? (string) $oldestFailedAt : null,
            'oldest_failed_job_age_minutes' => $this->ageInMinutes($oldestFailedAt),
            'queue_depth' => $queueDepth,
            'stalled_payouts' => $stalledPayouts,
            'attention_payouts' => $attentionPayouts,
        ];

        $problems = [];

        if ($failedJobs >= ($thresholds['failed_jobs'] ?? 1)) {
            $age = $metrics['oldest_failed_job_age_minutes'];
            $problems['failed_jobs'] = $failedJobs.' job(s) in '.$failedTable
                .($age === null ? '' : ', oldest '.$age.' minute(s) old')
                .'. Inspect with `queue:failed`, re-run with `queue:retry`.';
        }

        if ($queueDepth >= ($thresholds['queue_depth'] ?? 100)) {
            $problems['queue_depth'] = $queueDepth.' job(s) waiting in '.$jobsTable
                .'. The worker may not be draining; check laravel-queue-worker.';
        }

        if ($stalledPayouts >= ($thresholds['stalled_payouts'] ?? 1)) {
            $problems['stalled_payouts'] = $stalledPayouts.' payout(s) pending after an attempt. '
                .'`payouts:run --dispatch` does NOT re-queue these; use `payouts:retry`.';
        }

        if ($attentionPayouts >= ($thresholds['attention_payouts'] ?? 1)) {
            $problems['attention_payouts'] = $attentionPayouts.' payout(s) failed or needing review. '
                .'Use `payouts:lookup`, then `payouts:retry` or `payouts:release`.';
        }

        return [
            'healthy' => $problems === [],
            'checked_at' => now()->toIso8601String(),
            'metrics' => $metrics,
            'problems' => $problems,
        ];
    }

    private function ageInMinutes(mixed $timestamp): ?int
    {
        if (! $timestamp) {
            return null;
        }

        try {
            return (int) Carbon::parse((string) $timestamp)->diffInMinutes(now());
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{healthy:bool, metrics:array<string,mixed>, problems:array<string,string>} $report */
    private function render(array $report): void
    {
        $this->line('Queue health — '.($report['healthy'] ? '<info>OK</info>' : '<comment>NEEDS ATTENTION</comment>'));
        $this->newLine();

        foreach ($report['metrics'] as $key => $value) {
            $this->line(str_pad(str_replace('_', ' ', (string) $key), 32).' '.($value ?? '—'));
        }

        if ($report['problems'] !== []) {
            $this->newLine();
            foreach ($report['problems'] as $problem) {
                $this->warn('  • '.$problem);
            }
            $this->newLine();
            $this->line('Runbook: docs/production/paystack-runbook.md');
        }
    }

    /**
     * Mail the operator, at most once per cooldown window.
     *
     * A delivery failure must never fail the cron run on top of whatever is
     * already wrong — the non-zero exit and the log line still stand.
     *
     * @param  array{metrics:array<string,mixed>, problems:array<string,string>}  $report
     */
    private function notify(array $report): void
    {
        $to = config('operations.alerts.to');

        if (! $to) {
            $this->comment('No operations alert address configured; skipping mail.');

            return;
        }

        $cooldown = (int) config('operations.alerts.cooldown_minutes', 360);

        if ($cooldown > 0 && Cache::get(self::COOLDOWN_KEY)) {
            $this->comment('An alert was already sent within the cooldown window; skipping mail.');

            return;
        }

        try {
            Mail::to($to)->send(new QueueHealthAlertMail($report['problems'], $report['metrics']));

            if ($cooldown > 0) {
                Cache::put(self::COOLDOWN_KEY, now()->toIso8601String(), now()->addMinutes($cooldown));
            }

            $this->info('Operator alert sent to '.$to.'.');
        } catch (\Throwable $e) {
            report($e);
            $this->error('Could not send the operator alert: '.$e->getMessage());
        }
    }
}
