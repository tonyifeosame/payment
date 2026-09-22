<?php

namespace Tests\Feature;

use App\Mail\QueueHealthAlertMail;
use App\Models\Payout;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M5 — `jobs:check`, the hourly report that says whether queued work is healthy.
 *
 * It exists for the states the recovery layer deliberately does NOT pick up:
 * `payouts:run --dispatch` re-queues only obligations that were never queued
 * (attempts = 0), so a payout released back to `pending` after an attempt, or
 * parked in `failed`/`needs_review`, waits for an operator who currently has no
 * way of knowing it is there. Same for a job that exhausted its retries.
 *
 * Read-only: every assertion below also checks it changed nothing.
 */
class QueueHealthCheckTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        config([
            'operations.alerts.to' => 'ops@example.test',
            'operations.alerts.cooldown_minutes' => 360,
        ]);

        $this->school = $this->makeSchool('Alpha School', 'alpha');
    }

    private function payout(string $status, int $attempts, string $reference): Payout
    {
        return Payout::create([
            'school_id' => $this->school->id,
            'reference' => $reference,
            'amount' => 50000,
            'status' => $status,
            'attempts' => $attempts,
        ]);
    }

    private function failedJob(?string $failedAt = null): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\InitiateSchoolPayout']),
            'exception' => 'RuntimeException: boom',
            'failed_at' => $failedAt ?? now(),
        ]);
    }

    private function queuedJob(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\InitiateSchoolPayout']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
    }

    // --------------------------------------------------------------- healthy

    public function test_a_clean_system_reports_ok_and_exits_zero(): void
    {
        $this->artisan('jobs:check')
            ->expectsOutputToContain('Queue health')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_a_healthy_system_with_ordinary_work_in_flight_stays_quiet(): void
    {
        // A payout waiting to be picked up for the first time is normal: the
        // hourly cron re-queues exactly this shape.
        $this->payout(Payout::PENDING, 0, 'PO-fresh');
        $this->queuedJob();
        $this->payout(Payout::SUCCESS, 1, 'PO-done');

        $this->artisan('jobs:check')->assertExitCode(0);
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------- problems

    public function test_a_failed_job_makes_the_check_fail_and_reports_its_age(): void
    {
        $this->failedJob(Carbon::now()->subMinutes(90));

        $this->artisan('jobs:check')
            ->expectsOutputToContain('NEEDS ATTENTION')
            ->expectsOutputToContain('queue:retry')
            ->assertExitCode(1);

        $report = $this->jsonReport();
        $this->assertSame(1, $report['metrics']['failed_jobs']);
        $this->assertEqualsWithDelta(90, $report['metrics']['oldest_failed_job_age_minutes'], 2);
        $this->assertArrayHasKey('failed_jobs', $report['problems']);
    }

    public function test_a_payout_pending_after_an_attempt_is_surfaced(): void
    {
        // The gap this command exists for: payouts:run --dispatch filters on
        // attempts = 0, so nothing re-queues this one.
        $this->payout(Payout::PENDING, 2, 'PO-stalled');

        $this->artisan('jobs:check')
            ->expectsOutputToContain('payouts:retry')
            ->assertExitCode(1);

        $report = $this->jsonReport();
        $this->assertSame(1, $report['metrics']['stalled_payouts']);
        $this->assertArrayHasKey('stalled_payouts', $report['problems']);
    }

    public function test_failed_and_needs_review_payouts_are_surfaced(): void
    {
        $this->payout(Payout::FAILED, 1, 'PO-failed');
        $this->payout(Payout::NEEDS_REVIEW, 1, 'PO-review');

        $this->artisan('jobs:check')->assertExitCode(1);

        $report = $this->jsonReport();
        $this->assertSame(2, $report['metrics']['attention_payouts']);
        $this->assertArrayHasKey('attention_payouts', $report['problems']);
    }

    public function test_a_queue_backlog_past_the_threshold_is_surfaced(): void
    {
        config(['operations.queue_health.queue_depth' => 3]);

        $this->queuedJob();
        $this->queuedJob();
        $this->artisan('jobs:check')->assertExitCode(0); // 2 < 3

        $this->queuedJob();
        $this->artisan('jobs:check')
            ->expectsOutputToContain('laravel-queue-worker')
            ->assertExitCode(1);
    }

    // ----------------------------------------------------------- thresholds

    public function test_thresholds_are_configurable(): void
    {
        $this->failedJob();
        $this->failedJob();

        config(['operations.queue_health.failed_jobs' => 5]);
        $this->artisan('jobs:check')->assertExitCode(0);

        config(['operations.queue_health.failed_jobs' => 2]);
        $this->artisan('jobs:check')->assertExitCode(1);
    }

    // ----------------------------------------------------------------- json

    public function test_json_output_is_machine_readable_and_carries_the_exit_code(): void
    {
        $this->payout(Payout::FAILED, 1, 'PO-failed');

        $report = $this->jsonReport();

        $this->assertFalse($report['healthy']);
        $this->assertArrayHasKey('checked_at', $report);
        $this->assertSame(
            ['failed_jobs', 'oldest_failed_job_at', 'oldest_failed_job_age_minutes', 'queue_depth', 'stalled_payouts', 'attention_payouts'],
            array_keys($report['metrics'])
        );
    }

    // ------------------------------------------------------------- alerting

    public function test_an_unhealthy_check_emails_the_operator(): void
    {
        $this->payout(Payout::FAILED, 1, 'PO-failed');

        $this->artisan('jobs:check')->assertExitCode(1);

        Mail::assertSent(QueueHealthAlertMail::class, function (QueueHealthAlertMail $mail) {
            return $mail->hasTo('ops@example.test')
                && array_key_exists('attention_payouts', $mail->problems)
                && $mail->metrics['attention_payouts'] === 1;
        });
    }

    public function test_no_mail_is_sent_twice_inside_the_cooldown_window(): void
    {
        $this->payout(Payout::FAILED, 1, 'PO-failed');

        $this->artisan('jobs:check')->assertExitCode(1);
        Mail::assertSentCount(1);

        // Still broken an hour later: the exit code stands, the inbox is spared.
        $this->travel(61)->minutes();
        $this->artisan('jobs:check')->assertExitCode(1);
        Mail::assertSentCount(1);

        // Past the cooldown it speaks up again.
        $this->travel(6)->hours();
        $this->artisan('jobs:check')->assertExitCode(1);
        Mail::assertSentCount(2);
    }

    public function test_the_no_mail_flag_reports_without_sending(): void
    {
        $this->payout(Payout::FAILED, 1, 'PO-failed');

        $this->artisan('jobs:check', ['--no-mail' => true])->assertExitCode(1);

        Mail::assertNothingSent();
    }

    public function test_a_missing_alert_address_does_not_break_the_check(): void
    {
        config(['operations.alerts.to' => null]);
        $this->payout(Payout::FAILED, 1, 'PO-failed');

        $this->artisan('jobs:check')
            ->expectsOutputToContain('No operations alert address configured')
            ->assertExitCode(1);

        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------ read-only

    public function test_the_check_writes_nothing_to_the_ledger(): void
    {
        $stalled = $this->payout(Payout::PENDING, 2, 'PO-stalled');
        $failed = $this->payout(Payout::FAILED, 1, 'PO-failed');
        $this->failedJob();
        $this->queuedJob();

        $before = [
            'payouts' => Payout::orderBy('id')->get()->map->only(['id', 'status', 'attempts', 'amount'])->all(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'jobs' => DB::table('jobs')->count(),
        ];

        $this->artisan('jobs:check')->assertExitCode(1);

        $this->assertSame($before['payouts'], Payout::orderBy('id')->get()->map->only(['id', 'status', 'attempts', 'amount'])->all());
        $this->assertSame($before['failed_jobs'], DB::table('failed_jobs')->count());
        $this->assertSame($before['jobs'], DB::table('jobs')->count());
        $this->assertSame(Payout::PENDING, $stalled->fresh()->status);
        $this->assertSame(Payout::FAILED, $failed->fresh()->status);
    }

    /** @return array{healthy:bool, checked_at:string, metrics:array<string,mixed>, problems:array<string,string>} */
    private function jsonReport(): array
    {
        // Artisan::call rather than $this->artisan(): PendingCommand buffers its
        // own output, so Artisan::output() would come back empty.
        Artisan::call('jobs:check', ['--json' => true, '--no-mail' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }
}
