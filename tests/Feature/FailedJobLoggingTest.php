<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * M5 — every job that exhausts its retries says so, in one shape.
 *
 * Only InitiateSchoolPayout had a failed() hook, so a receipt mailable that gave
 * up left nothing behind but a `failed_jobs` row nobody reads. The Queue::failing
 * listener in bootstrap/app.php closes that.
 *
 * These tests deliberately run on the DATABASE queue connection rather than the
 * suite's `sync` default (phpunit.xml). Under `sync` a job throws straight back
 * into the caller and never fails in the queue sense at all, so the production
 * driver, the `failed_jobs` table and this listener were exercised by nothing.
 */
class FailedJobLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    /** Push, then run the worker once so the job fails for real. */
    private function workOnce(): void
    {
        $this->artisan('queue:work', [
            '--once' => true,
            '--tries' => 1,
            '--no-interaction' => true,
        ])->run();
    }

    public function test_a_job_that_exhausts_its_retries_is_logged_as_critical(): void
    {
        $captured = [];

        Log::listen(function ($message) use (&$captured) {
            if ($message->level === 'critical') {
                $captured[] = $message;
            }
        });

        AlwaysFailingTestJob::dispatch();
        $this->assertSame(1, DB::table('jobs')->count(), 'the job was not queued');

        $this->workOnce();

        $critical = collect($captured)->firstWhere('message', 'Queued job failed permanently');

        $this->assertNotNull($critical, 'a permanently failed job produced no critical log entry');
        $this->assertSame('database', $critical->context['connection']);
        $this->assertSame('default', $critical->context['queue']);
        $this->assertStringContainsString('AlwaysFailingTestJob', $critical->context['job']);
        $this->assertSame(1, $critical->context['attempts']);
        $this->assertStringContainsString('deliberate failure', $critical->context['exception']);
    }

    public function test_the_failure_is_identifiable_without_leaking_the_payload(): void
    {
        $captured = [];

        Log::listen(function ($message) use (&$captured) {
            if ($message->level === 'critical') {
                $captured[] = $message;
            }
        });

        AlwaysFailingTestJob::dispatch('parent@example.test');
        $this->workOnce();

        $critical = collect($captured)->firstWhere('message', 'Queued job failed permanently');
        $this->assertNotNull($critical);

        // Enough to find the job, and nothing that carries payer data.
        $this->assertSame(
            ['connection', 'queue', 'job', 'job_id', 'attempts', 'exception'],
            array_keys($critical->context)
        );
        $this->assertStringNotContainsString('parent@example.test', json_encode($critical->context));
        $this->assertNotNull($critical->context['job_id']);
    }

    public function test_the_failed_job_is_recorded_in_the_failed_jobs_table(): void
    {
        AlwaysFailingTestJob::dispatch();
        $this->workOnce();

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count(), 'the job was left on the queue');

        // And jobs:check now sees it — the listener logs, the command reports.
        $this->artisan('jobs:check', ['--no-mail' => true])->assertExitCode(1);
    }

    public function test_a_successful_job_logs_nothing_and_leaves_no_failure(): void
    {
        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            if ($message->level === 'critical') {
                $captured[] = $message;
            }
        });

        SucceedingTestJob::dispatch();
        $this->workOnce();

        $this->assertSame([], $captured);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }
}

/** A job whose only purpose is to fail. */
class AlwaysFailingTestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public ?string $email = null) {}

    public function handle(): void
    {
        throw new \RuntimeException('deliberate failure for the M5 listener test');
    }
}

/** Its counterpart, so "logs nothing on success" is a real assertion. */
class SucceedingTestJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // nothing
    }
}
