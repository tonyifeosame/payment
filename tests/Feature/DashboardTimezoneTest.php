<?php

namespace Tests\Feature;

use App\Models\School;
use App\Services\SchoolDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Dashboard "today" / "this week" are business-day boundaries in the school's
 * reporting timezone (Africa/Lagos, UTC+1, no DST), while every timestamp is
 * stored in UTC. The boundaries must therefore be computed in Lagos time and
 * converted to UTC before they reach the query — otherwise a payment made at
 * 00:30 Lagos is reported as "yesterday" until 01:00.
 */
class DashboardTimezoneTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('UTC', config('app.timezone'), 'these tests assume UTC storage');
        config(['fees.reporting_timezone' => 'Africa/Lagos']);

        $this->school = $this->makeSchool('Alpha School', 'alpha');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stats(): array
    {
        return app(SchoolDashboardService::class)->build($this->school, null);
    }

    public function test_a_payment_just_after_lagos_midnight_counts_as_today(): void
    {
        // It is 01:30 Tuesday 16 Sep in Lagos (00:30 UTC).
        Carbon::setTestNow('2026-09-16 00:30:00');

        // Paid at 00:20 Lagos on the 16th — i.e. 23:20 UTC on the 15th.
        $this->makeSuccessfulTransaction($this->school, ['fee_amount' => 10000, 'paid_at' => Carbon::parse('2026-09-15 23:20:00')]);
        // Paid at 23:40 Lagos on the 15th — yesterday.
        $this->makeSuccessfulTransaction($this->school, ['fee_amount' => 500, 'paid_at' => Carbon::parse('2026-09-15 22:40:00')]);

        $today = $this->stats()['today'];

        $this->assertSame(1, $today['count']);
        $this->assertEquals(10000.00, $today['net']);
    }

    public function test_a_payment_before_lagos_midnight_is_not_today_even_though_it_is_the_same_utc_day(): void
    {
        // It is 00:30 Tuesday 16 Sep in Lagos (23:30 UTC on Monday the 15th).
        Carbon::setTestNow('2026-09-15 23:30:00');

        // 23:30 Lagos on the 15th (22:30 UTC): yesterday in Lagos, but the same UTC day as "now".
        $this->makeSuccessfulTransaction($this->school, ['fee_amount' => 7000, 'paid_at' => Carbon::parse('2026-09-15 22:30:00')]);
        // 00:10 Lagos on the 16th (23:10 UTC): today.
        $this->makeSuccessfulTransaction($this->school, ['fee_amount' => 3000, 'paid_at' => Carbon::parse('2026-09-15 23:10:00')]);

        $today = $this->stats()['today'];

        $this->assertSame(1, $today['count']);
        $this->assertEquals(3000.00, $today['net']);
    }

    public function test_the_week_starts_on_monday_midnight_lagos(): void
    {
        // Monday 14 Sep, 01:30 Lagos (00:30 UTC).
        Carbon::setTestNow('2026-09-14 00:30:00');

        // Monday 00:30 Lagos = Sunday 23:30 UTC: this week.
        $this->makeSuccessfulTransaction($this->school, ['fee_amount' => 8000, 'paid_at' => Carbon::parse('2026-09-13 23:30:00')]);
        // Sunday 23:30 Lagos = Sunday 22:30 UTC: last week.
        $this->makeSuccessfulTransaction($this->school, ['fee_amount' => 900, 'paid_at' => Carbon::parse('2026-09-13 22:30:00')]);

        $week = $this->stats()['week'];

        $this->assertSame(1, $week['count']);
        $this->assertEquals(8000.00, $week['net']);
    }

    public function test_recent_payments_are_displayed_in_lagos_time(): void
    {
        Carbon::setTestNow('2026-09-16 10:00:00');
        $this->makeSuccessfulTransaction($this->school, ['paid_at' => Carbon::parse('2026-09-15 23:20:00')]);

        $this->actingAsSchoolAdmin($this->school)
            ->get('/s/alpha/dashboard')
            ->assertOk()
            ->assertSee('16 Sep, 00:20')
            ->assertDontSee('15 Sep, 23:20');
    }
}
