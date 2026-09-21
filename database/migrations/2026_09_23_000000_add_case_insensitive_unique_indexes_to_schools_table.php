<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H4: a school's NAME (its login identifier) and EMAIL (its password-reset
 * identifier) must each be unique regardless of case, and the database — not
 * only the registration form — must enforce it, so two registrations racing
 * past validation cannot both succeed.
 *
 * Neither PostgreSQL (production) nor SQLite (local/tests) compares strings
 * case-insensitively by default, so a plain unique index would still admit
 * "Alpha School" beside "alpha school" — which the case-insensitive login would
 * then find twice. The indexes are therefore built on LOWER(name) and
 * LOWER(email): expression indexes, supported by PostgreSQL, SQLite (≥ 3.9) and
 * MySQL (≥ 8.0.13). NULL emails stay allowed and are not considered equal.
 *
 * Historical duplicates: this migration REFUSES to run over existing rows that
 * would violate the rule, and changes nothing. It never deletes, merges or
 * renames a school. If it fails, the message lists the offending groups; an
 * operator renames (or corrects the email of) the affected schools by hand — in
 * agreement with them, since the name is their login — and re-runs `migrate`.
 * Until then the application already fails closed: an ambiguous name cannot
 * log in and an ambiguous email cannot reset a password.
 *
 * Reversible: down() drops the two indexes only.
 */
return new class extends Migration
{
    private const NAME_INDEX = 'schools_name_lower_unique';

    private const EMAIL_INDEX = 'schools_email_lower_unique';

    public function up(): void
    {
        $this->guardAgainstDuplicates();

        $driver = Schema::getConnection()->getDriverName();

        foreach ([self::NAME_INDEX => 'name', self::EMAIL_INDEX => 'email'] as $index => $column) {
            DB::statement(match ($driver) {
                'pgsql', 'sqlite' => "CREATE UNIQUE INDEX {$index} ON schools (LOWER({$column}))",
                'mysql', 'mariadb' => "CREATE UNIQUE INDEX {$index} ON schools ((LOWER({$column})))",
                default => throw new RuntimeException("Unsupported database driver [{$driver}] for expression indexes on schools."),
            });
        }
    }

    public function down(): void
    {
        foreach ([self::NAME_INDEX, self::EMAIL_INDEX] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}".(Schema::getConnection()->getDriverName() === 'mysql' ? ' ON schools' : ''));
        }
    }

    /**
     * Refuse to add uniqueness over data that already violates it, naming the
     * groups so an operator can resolve them. Read-only.
     */
    private function guardAgainstDuplicates(): void
    {
        $problems = [];
        $ids = Schema::getConnection()->getDriverName() === 'pgsql' ? "STRING_AGG(id::text, ',')" : 'GROUP_CONCAT(id)';

        foreach (['name', 'email'] as $column) {
            $groups = DB::table('schools')
                ->selectRaw("LOWER({$column}) as value, COUNT(*) as n, {$ids} as ids")
                ->whereNotNull($column)
                ->groupByRaw("LOWER({$column})")
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($groups as $group) {
                $problems[] = sprintf('%s "%s" is shared by school ids %s', $column, $group->value, $group->ids);
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Cannot add case-insensitive unique indexes on schools.name / schools.email: '.count($problems).' duplicate group(s) exist. '
                .'No data was changed. Resolve each by hand (rename the school, or correct its email — the name is its login name, so agree it with the school), then re-run migrate. '
                .implode('; ', $problems)
            );
        }
    }
};
