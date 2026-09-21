<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the settlement row lock is real on the production engine.
 *
 * SQLite compiles lockForUpdate() to an empty string, so the rest of the suite
 * exercises the *logic* of the once-only transition but never the lock itself.
 * This test opens two independent PostgreSQL connections and shows that a row
 * locked by one is genuinely unavailable to the other.
 *
 * It is skipped unless a PostgreSQL test database is configured. To enable:
 *
 *   PGSQL_TEST_HOST=127.0.0.1 PGSQL_TEST_PORT=5432 \
 *   PGSQL_TEST_DATABASE=payment_test PGSQL_TEST_USERNAME=postgres \
 *   PGSQL_TEST_PASSWORD=secret php artisan test --filter=PostgresRowLockTest
 *
 * In CI, set those variables against a postgres service container.
 */
class PostgresRowLockTest extends TestCase
{
    private const TABLE = 'row_lock_probe';

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql is not loaded.');
        }

        $host = env('PGSQL_TEST_HOST');
        if (! $host) {
            $this->markTestSkipped('No PostgreSQL test database configured (set PGSQL_TEST_HOST to enable).');
        }

        $base = [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => env('PGSQL_TEST_PORT', '5432'),
            'database' => env('PGSQL_TEST_DATABASE', 'payment_test'),
            'username' => env('PGSQL_TEST_USERNAME', 'postgres'),
            'password' => (string) env('PGSQL_TEST_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];

        config(['database.connections.pg_a' => $base, 'database.connections.pg_b' => $base]);

        try {
            DB::connection('pg_a')->getPdo();
            DB::connection('pg_b')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL test database unreachable: '.$e->getMessage());
        }

        Schema::connection('pg_a')->dropIfExists(self::TABLE);
        DB::connection('pg_a')->statement(
            'create table '.self::TABLE.' (id serial primary key, status varchar(32) not null, paid_at timestamp null)'
        );
        DB::connection('pg_a')->table(self::TABLE)->insert(['status' => 'pending']);
    }

    protected function tearDown(): void
    {
        try {
            foreach (['pg_a', 'pg_b'] as $c) {
                if (DB::connection($c)->transactionLevel() > 0) {
                    DB::connection($c)->rollBack();
                }
            }
            Schema::connection('pg_a')->dropIfExists(self::TABLE);
        } catch (\Throwable) {
            // nothing to clean up
        }

        parent::tearDown();
    }

    public function test_lock_for_update_emits_a_real_lock_on_postgres(): void
    {
        $sql = DB::connection('pg_a')->table(self::TABLE)->where('id', 1)->lockForUpdate()->toSql();

        $this->assertStringContainsString('for update', $sql);
    }

    public function test_a_locked_row_is_unavailable_to_a_second_connection(): void
    {
        $a = DB::connection('pg_a');
        $b = DB::connection('pg_b');

        $a->beginTransaction();
        $a->table(self::TABLE)->where('id', 1)->lockForUpdate()->first();

        // NOWAIT turns "would block" into an immediate error, so the test cannot hang.
        $blocked = false;
        try {
            $b->beginTransaction();
            $b->select('select * from '.self::TABLE.' where id = 1 for update nowait');
        } catch (\Throwable $e) {
            $blocked = true;
        } finally {
            if ($b->transactionLevel() > 0) {
                $b->rollBack();
            }
        }

        $a->rollBack();

        $this->assertTrue($blocked, 'PostgreSQL did not actually lock the row — settlement is not serialised.');
    }

    public function test_second_writer_observes_the_committed_transition(): void
    {
        $a = DB::connection('pg_a');
        $b = DB::connection('pg_b');

        // A settles and commits.
        $a->transaction(function () use ($a) {
            $row = $a->table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            $this->assertSame('pending', $row->status);
            $a->table(self::TABLE)->where('id', 1)->update(['status' => 'success', 'paid_at' => now()]);
        });

        // B then acquires the lock and must see A's committed state, so its own
        // re-check short-circuits exactly as PaymentSettlementService relies on.
        $b->transaction(function () use ($b) {
            $row = $b->table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            $this->assertSame('success', $row->status, 'second writer did not observe the committed transition');
        });
    }

    /**
     * B1 recovery: an operator reset (failed -> pending) and a concurrent worker
     * claim (pending -> initiating) both re-read the row under `for update`, so
     * the second of two operators, or an operator racing the worker, observes
     * the first writer's committed state and its own guard refuses. This is the
     * mechanism PayoutService::retryFailed() / releaseForTransfer() rely on.
     */
    public function test_a_recovery_reset_is_serialised_by_the_row_lock(): void
    {
        $a = DB::connection('pg_a');
        $b = DB::connection('pg_b');

        $a->table(self::TABLE)->where('id', 1)->update(['status' => 'failed']);

        // Operator A holds the lock while resetting the payout.
        $a->beginTransaction();
        $rowA = $a->table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
        $this->assertSame('failed', $rowA->status);
        $a->table(self::TABLE)->where('id', 1)->update(['status' => 'pending']);

        // Operator B cannot even read the row for update until A is done.
        $blocked = false;
        try {
            $b->beginTransaction();
            $b->select('select * from '.self::TABLE.' where id = 1 for update nowait');
        } catch (\Throwable) {
            $blocked = true;
        } finally {
            if ($b->transactionLevel() > 0) {
                $b->rollBack();
            }
        }
        $this->assertTrue($blocked, 'a second operator was not blocked by the first reset');

        $a->commit();

        // Once A has committed, B sees `pending`, so its "only failed may be reset"
        // guard refuses — and the worker's conditional claim is the only writer left.
        $b->transaction(function () use ($b) {
            $row = $b->table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
            $this->assertSame('pending', $row->status);
        });

        $claimed = $b->table(self::TABLE)->where('id', 1)->where('status', 'pending')->update(['status' => 'initiating']);
        $this->assertSame(1, $claimed);
        $claimedAgain = $a->table(self::TABLE)->where('id', 1)->where('status', 'pending')->update(['status' => 'initiating']);
        $this->assertSame(0, $claimedAgain, 'a second claim must find nothing to claim');
    }
}
