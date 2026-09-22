<?php

namespace Tests\Concerns;

use App\Models\AcademicSession;
use App\Models\Category;
use App\Models\School;
use App\Models\SchoolLogo;
use App\Models\Student;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Services\AcademicPeriodService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * Builders shared by the Phase 1 feature tests. Every builder takes the school
 * explicitly so a test can never accidentally create cross-tenant data.
 */
trait InteractsWithSchools
{
    protected function makeSchool(string $name, string $slug, array $overrides = []): School
    {
        return School::create(array_merge([
            'name' => $name,
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'admin_password' => Hash::make('password123'),
            'account_number' => '0123456789',
            'account_name' => 'Acct '.$name,
            'bank' => 'GTB',
            'bank_code' => '058',
        ], $overrides));
    }

    /** A stored logo (H3): the school_logos row an upload would have produced. */
    protected function giveLogo(School $school, string $name = 'logo.png', int $width = 120, int $height = 120): SchoolLogo
    {
        $logo = SchoolLogo::create(['school_id' => $school->id] + SchoolLogo::attributesFor(UploadedFile::fake()->image($name, $width, $height)));
        $school->forgetLogoState();

        return $logo;
    }

    /** Exactly the session a real login produces: school id + password fingerprint. */
    protected function actingAsSchoolAdmin(School $school): static
    {
        $this->withSession(\App\Support\SchoolSession::payloadFor($school));

        return $this;
    }

    protected function makeSessionWithTerms(School $school, string $name = '2026/2027'): AcademicSession
    {
        return app(AcademicPeriodService::class)->createSession($school, $name);
    }

    protected function makeFee(School $school, string $categoryName, string $feeName, float $price, ?int $termId = null): Subcategory
    {
        $category = Category::firstOrCreate(['school_id' => $school->id, 'name' => $categoryName]);

        return Subcategory::create([
            'school_id' => $school->id,
            'category_id' => $category->id,
            'name' => $feeName,
            'price' => $price,
            'academic_term_id' => $termId,
        ]);
    }

    protected function makeStudent(School $school, string $admission, string $name = 'Test Student', string $class = 'JSS 1', array $overrides = []): Student
    {
        return Student::create(array_merge([
            'school_id' => $school->id,
            'full_name' => $name,
            'admission_number' => $admission,
            'class_name' => $class,
        ], $overrides));
    }

    /** A settled payment with a trustworthy fee split, as the checkout service would record it. */
    protected function makeSuccessfulTransaction(School $school, array $overrides = []): Transaction
    {
        $base = (float) ($overrides['fee_amount'] ?? 50000);
        $fee = (float) ($overrides['service_fee'] ?? round($base * 0.025, 2));

        return Transaction::create(array_merge([
            'school_id' => $school->id,
            'reference' => 'ref-'.fake()->unique()->uuid(),
            'amount' => $base + $fee,
            'fee_amount' => $base,
            'service_fee' => $fee,
            'status' => 'success',
            'paid_at' => now(),
            'email' => 'payer@example.test',
            'name' => 'Payer Name',
            'category_name' => 'School Fees',
            'subcategory_name' => 'Tuition',
            'meta_data' => ['quantity' => 1, 'base_amount' => $base, 'markup_amount' => $fee],
        ], $overrides));
    }
}
