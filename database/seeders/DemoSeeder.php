<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Category;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use App\Models\Subcategory;
use App\Services\AcademicPeriodService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one fully-formed school for the local customer demo.
 *
 * This exists because the older CategorySeeder predates multi-tenancy: it creates
 * categories with no school_id, and every customer-facing page filters by
 * school_id, so those rows are invisible on the public payment page. Seeding this
 * instead gives you a school you can actually log into and pay.
 *
 * Idempotent — re-running it updates the same rows rather than duplicating the
 * school or doubling its fee list, so `db:seed --class=DemoSeeder` is safe to
 * repeat between demo runs.
 *
 * The bank details are Paystack TEST-mode values and the account is never charged;
 * the payout leg still goes through the real Paystack test API.
 */
class DemoSeeder extends Seeder
{
    public const SCHOOL_SLUG = 'demo-academy';

    public const SCHOOL_NAME = 'Demo Academy';

    public const ADMIN_PASSWORD = 'demo-password';

    public const SESSION_NAME = '2026/2027';

    /**
     * The demo school's class ladder, first to last. Demo data only: a real school
     * defines its own on the Classes page and nothing is ever inferred from names.
     */
    public const CLASS_LADDER = [
        'Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'Primary 6',
        'JSS 1', 'JSS 2', 'JSS 3', 'SS 1', 'SS 2', 'SS 3',
    ];

    /** admission number => [name, class] — the roster parents pay against. */
    public const STUDENTS = [
        'DA/2026/001' => ['Adaeze Okonkwo', 'JSS 1'],
        'DA/2026/002' => ['Tunde Bakare', 'JSS 1'],
        'DA/2026/003' => ['Chiamaka Eze', 'JSS 2'],
        'DA/2025/014' => ['Ibrahim Musa', 'Primary 5'],
        'DA/2025/021' => ['Blessing Adeyemi', 'Primary 3'],
    ];

    public function run(): void
    {
        $school = School::updateOrCreate(
            ['slug' => self::SCHOOL_SLUG],
            [
                'name' => self::SCHOOL_NAME,
                'email' => 'admin@demo-academy.test',
                // Hashed on the way in: SchoolAuthController::login uses Hash::check.
                'admin_password' => Hash::make(self::ADMIN_PASSWORD),
                'account_number' => '0000000000',
                'account_name' => 'Demo Academy',
                'bank' => 'Zenith Bank',
                'bank_code' => '057',
                'address' => '1 Demo Road, Lagos',
            ]
        );

        // Deliberately NOT setting paystack_recipient_code: leaving it null exercises
        // PaystackService::ensureRecipientForSchool during the payout leg, which is
        // the path a real new school takes.

        // One academic session with its three terms; First Term is current.
        $session = AcademicSession::where('school_id', $school->id)->where('name', self::SESSION_NAME)->first()
            ?? app(AcademicPeriodService::class)->createSession($school, self::SESSION_NAME);
        $firstTerm = $session->terms()->where('number', 1)->first();
        if ($school->current_academic_term_id === null) {
            $school->forceFill(['current_academic_term_id' => $firstTerm->id])->save();
        }

        // Term fees are tied to First Term; uniform is general (any term).
        $fees = [
            'School Fees' => [
                ['name' => 'Primary - First Term', 'price' => 50000, 'term' => $firstTerm->id],
                ['name' => 'Secondary - First Term', 'price' => 80000, 'term' => $firstTerm->id],
            ],
            'Uniform' => [
                ['name' => 'Shirt', 'price' => 3000, 'term' => null],
                ['name' => 'Trousers', 'price' => 4000, 'term' => null],
            ],
        ];

        foreach ($fees as $categoryName => $subcategories) {
            $category = Category::updateOrCreate(
                ['school_id' => $school->id, 'name' => $categoryName],
                []
            );

            foreach ($subcategories as $sub) {
                Subcategory::updateOrCreate(
                    [
                        'school_id' => $school->id,
                        'category_id' => $category->id,
                        'name' => $sub['name'],
                    ],
                    ['price' => $sub['price'], 'academic_term_id' => $sub['term']]
                );
            }

            // updateOrCreate matches on name, so renaming a fee in the list above
            // would otherwise leave the old row behind and the demo page would show
            // both. Prune anything in this category that is no longer listed.
            Subcategory::where('school_id', $school->id)
                ->where('category_id', $category->id)
                ->whereNotIn('name', array_column($subcategories, 'name'))
                ->delete();
        }

        // Same reasoning one level up, for a category that has been dropped.
        Category::where('school_id', $school->id)
            ->whereNotIn('name', array_keys($fees))
            ->delete();

        // Class ladder, in order. Idempotent on name; positions follow the list above.
        $levels = [];
        foreach (self::CLASS_LADDER as $i => $className) {
            $levels[$className] = ClassLevel::updateOrCreate(
                ['school_id' => $school->id, 'name' => $className],
                ['position' => $i + 1, 'is_active' => true]
            );
        }

        // Demo students are mapped to their level by the exact name in STUDENTS —
        // this is seed data we wrote ourselves, not a guess about a real roster.
        foreach (self::STUDENTS as $admission => [$name, $class]) {
            Student::updateOrCreate(
                ['school_id' => $school->id, 'admission_number' => $admission],
                [
                    'full_name' => $name,
                    'class_name' => $class,
                    'class_level_id' => $levels[$class]?->id,
                    'academic_session_id' => $session->id,
                ]
            );
        }

        $this->command?->info('Demo school ready.');
        $this->command?->line('  Public payment page: /s/'.self::SCHOOL_SLUG.'/payment');
        $this->command?->line('  Admin login:         /admin/login');
        $this->command?->line('  School name:         '.self::SCHOOL_NAME);
        $this->command?->line('  Admin password:      '.self::ADMIN_PASSWORD);
        $this->command?->line('  Session / term:      '.self::SESSION_NAME.' — First Term (current)');
        $this->command?->line('  Students:            '.implode(', ', array_keys(self::STUDENTS)));
        $this->command?->line('  Classes:             '.self::CLASS_LADDER[0].' … '.self::CLASS_LADDER[count(self::CLASS_LADDER) - 1]);
    }
}
