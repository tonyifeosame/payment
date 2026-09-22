<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * M3 — one definition of "what time does this school think it is".
 *
 * Timestamps are stored in `app.timezone` (UTC) and always will be: the columns
 * are `timestamp`/`dateTime` WITHOUT a zone, `paid_at` is compared against
 * Paystack's own instants, and every query compares against `now()`. Nothing
 * here changes storage.
 *
 * What it fixes is the edge. Before this, only the dashboard converted to the
 * reporting zone; the transaction list, receipts, CSV and payouts printed raw
 * UTC. For any payment settled between 00:00 and 01:00 Lagos — 23:00-00:00 UTC
 * the day before — the dashboard counted it as "today" and dated it e.g. 16 Sep
 * while the ledger, the CSV and the parent's receipt all said 15 Sep.
 *
 * Display and FILTER boundaries have to move together. A `Y-m-d` filter parsed
 * in UTC means 01:00 in the reporting zone, so converting only the display would
 * have left a row shown as "16 Sep" outside a `date_from=2026-09-16` filter —
 * worse than the inconsistency it replaced. startOfDay()/endOfDay() below parse
 * in the reporting zone and hand back the same instant in storage time, which is
 * exactly the manoeuvre SchoolDashboardService has always used for "today".
 *
 * The zone is always read from config (`fees.reporting_timezone`, env
 * REPORTING_TIMEZONE, default Africa/Lagos, validated by `paystack:check`).
 * Nigeria is WAT, UTC+1, with no DST, so conversion is a fixed offset with no
 * ambiguous or nonexistent local times — but nothing here assumes that.
 *
 * Deliberately NOT used by RunPayouts: its --day/--since filters are operator
 * scoping for money movement rather than presentation, so they stay on UTC day
 * boundaries. Converting them would change which payouts a manual run picks up.
 * See docs/production/paystack-runbook.md §6b.
 */
final class BusinessTime
{
    /** The timezone school admins and parents think in. */
    public static function zone(): string
    {
        return (string) config('fees.reporting_timezone', 'Africa/Lagos');
    }

    /** Where timestamps are stored, and compared. */
    public static function storageZone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /**
     * A stored instant, ready to print. Null-safe so views can keep using
     * `$when?->format(...)` against a nullable column.
     */
    public static function display(?Carbon $at): ?Carbon
    {
        return $at?->copy()->timezone(self::zone());
    }

    /**
     * The first instant of a `Y-m-d` filter day, in storage time.
     *
     * Null for anything that is not a usable date, so callers can treat a blank
     * or malformed filter as "no bound" exactly as they did before.
     */
    public static function startOfDay(?string $date): ?Carbon
    {
        return self::parse($date)?->startOfDay()->setTimezone(self::storageZone());
    }

    /** The last instant of a `Y-m-d` filter day, in storage time. */
    public static function endOfDay(?string $date): ?Carbon
    {
        return self::parse($date)?->endOfDay()->setTimezone(self::storageZone());
    }

    /**
     * A short label for column headings and receipt footers, so an exported file
     * or a re-rendered receipt says which clock it is quoting: the abbreviation
     * when the zone has one (WAT), otherwise the identifier.
     */
    public static function label(): string
    {
        $abbreviation = Carbon::now(self::zone())->format('T');

        // A zone without a named abbreviation formats as a numeric offset
        // (+01, -0500); the identifier reads better than that.
        return preg_match('/^[A-Za-z]{2,5}$/', $abbreviation) ? $abbreviation : self::zone();
    }

    /**
     * A `Y-m-d` filter value read in the reporting zone, or null.
     *
     * The shape is checked before parsing — the stricter of the two helpers this
     * replaces — so a malformed filter means "no bound" rather than whatever
     * date PHP would roll it over to. The time component is irrelevant: both
     * callers immediately take startOfDay()/endOfDay().
     */
    private static function parse(?string $date): ?Carbon
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date))) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($date), self::zone()) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
