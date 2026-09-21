<?php

namespace App\Console\Commands;

use App\Services\PaystackReadiness;
use Illuminate\Console\Command;

/**
 * Operator pre-production checklist for the Paystack integration (B2).
 *
 *   php artisan paystack:check                 human-readable report
 *   php artisan paystack:check --production    apply production rules whatever APP_ENV is (CI)
 *   php artisan paystack:check --no-api        skip the two read-only Paystack lookups
 *   php artisan paystack:check --json          machine-readable report
 *
 * Read-only: no charge, no transfer, no payout, no row written, no mail, no
 * change to Paystack. The only network calls are GET /bank and GET /balance
 * through PaystackService's non-throwing lookup client. No secret is printed —
 * keys are reported as configured/missing and live/test only.
 *
 * Exit codes: 0 when nothing FAILs; 1 when at least one required application-side
 * prerequisite FAILs. WARN and MANUAL never change the exit code — MANUAL items
 * are the Paystack-dashboard and Render facts this code cannot verify, listed so
 * an operator confirms each one by hand before going live.
 */
class PaystackCheck extends Command
{
    protected $signature = 'paystack:check
        {--production : Apply production rules (HTTPS APP_URL, live key, real mailer and queue) regardless of APP_ENV}
        {--no-api : Skip the read-only Paystack API calls}
        {--json : Print the report as JSON}';

    protected $description = 'Read-only Paystack go-live checklist: what this code verifies, what is missing, and what to confirm by hand';

    public function handle(PaystackReadiness $readiness): int
    {
        $production = (bool) $this->option('production') || app()->environment('production');
        $checks = $readiness->run($production, ! $this->option('no-api'));
        $summary = PaystackReadiness::summarise($checks);
        $exit = $summary[PaystackReadiness::FAIL] > 0 ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->line(json_encode([
                'production_rules' => $production,
                'summary' => $summary,
                'exit_code' => $exit,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->info('FEYRA — Paystack readiness check (read-only)');
        $this->line('Statuses: PASS verified here · WARN look before go-live · FAIL required and missing · MANUAL confirm in Paystack/Render');

        $group = null;
        foreach ($checks as $check) {
            if ($check['group'] !== $group) {
                $group = $check['group'];
                $this->newLine();
                $this->line("<options=bold>{$group}</>");
            }
            $this->line(sprintf('  [%s] %s — %s', $this->badge($check['status']), $check['label'], $check['detail']));
        }

        $this->newLine();
        $this->line(sprintf('PASS %d · WARN %d · FAIL %d · MANUAL %d', $summary['PASS'], $summary['WARN'], $summary['FAIL'], $summary['MANUAL']));
        $this->line('Runbook: docs/production/paystack-runbook.md');

        if ($exit !== self::SUCCESS) {
            $this->error('Not ready: fix every FAIL above, then re-run.');
        } elseif ($summary['MANUAL'] > 0) {
            $this->comment('No application-side FAIL. Confirm every MANUAL item in the Paystack dashboard and Render before going live.');
        }

        return $exit;
    }

    private function badge(string $status): string
    {
        return match ($status) {
            PaystackReadiness::PASS => '<fg=green>PASS</>',
            PaystackReadiness::WARN => '<fg=yellow>WARN</>',
            PaystackReadiness::FAIL => '<fg=red;options=bold>FAIL</>',
            default => '<fg=cyan>MANUAL</>',
        };
    }
}
