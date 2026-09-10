<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
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

        $fees = [
            'School Fees' => [
                ['name' => 'Primary - Term 1', 'price' => 50000],
                ['name' => 'Secondary - Term 1', 'price' => 80000],
            ],
            'Uniform' => [
                ['name' => 'Shirt', 'price' => 3000],
                ['name' => 'Trousers', 'price' => 4000],
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
                    ['price' => $sub['price']]
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

        $this->command?->info('Demo school ready.');
        $this->command?->line('  Public payment page: /s/'.self::SCHOOL_SLUG.'/payment');
        $this->command?->line('  Admin login:         /admin/login');
        $this->command?->line('  School name:         '.self::SCHOOL_NAME);
        $this->command?->line('  Admin password:      '.self::ADMIN_PASSWORD);
    }
}
