<?php

namespace Tests\Feature;

use App\Models\ClassLevel;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The Fees page's CSV export: the school's own fees, in page order, at the school's
 * own prices — never the platform service fee or what a parent is charged online.
 */
class FeeExportTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fees.markup_percent' => 2.5]);

        $this->alpha = $this->makeSchool('Alpha Academy', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $term = $this->makeSessionWithTerms($this->alpha, '2026/2027')->terms()->where('number', 1)->firstOrFail();
        $jss1 = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS 1', 'position' => 1, 'is_active' => true]);
        $jss2 = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS 2', 'position' => 2, 'is_active' => true]);

        $tuition = $this->makeFee($this->alpha, 'School Fees', 'JSS First Term Fees', 50000, $term->id);
        $tuition->forceFill(['is_tuition' => true])->save();
        $tuition->classLevels()->sync([$jss2->id => ['school_id' => $this->alpha->id], $jss1->id => ['school_id' => $this->alpha->id]]);

        $this->makeFee($this->alpha, 'Books', 'Textbooks', 7500, null, allowsQuantity: true);
        $draft = $this->makeFee($this->alpha, 'Books', 'Workbook', 1, null);
        $draft->update(['price' => null]);
        $this->makeFee($this->alpha, 'Uniform', '=HYPERLINK("http://evil")', 3000, null);

        $this->makeFee($this->beta, 'Secret', 'Beta Only Fee', 99999, null);
    }

    private function rows(): array
    {
        $response = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/subcategories/export')->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/attachment; filename=.?alpha-fees-\d{8}-\d{6}\.csv/', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        return array_map('str_getcsv', array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));
    }

    public function test_the_fees_page_has_an_export_button(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/subcategories')
            ->assertOk()->assertSee('Export CSV')->assertSee('/admin/alpha/subcategories/export', false);
    }

    public function test_the_export_lists_the_schools_fees_in_page_order(): void
    {
        $rows = $this->rows();

        $this->assertSame(['Fee', 'Type', 'Category', 'Amount (NGN)', 'Multiple allowed', 'Applies to', 'Academic year', 'Term'], $rows[0]);
        $this->assertSame(['JSS First Term Fees', 'School fees', 'School Fees', '50000.00', 'No', 'JSS 1, JSS 2', '2026/2027', 'First Term'], $rows[1]);

        $byName = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertSame(['Textbooks', 'Additional fee', 'Books', '7500.00', 'Yes', 'All classes', '', 'Any term'], $byName['Textbooks']);
        // An unpriced (draft) fee exports with no amount, never a made-up one.
        $this->assertSame('', $byName['Workbook'][3]);
        $this->assertCount(5, $rows);
    }

    public function test_the_export_never_contains_service_fees_or_parent_totals(): void
    {
        $csv = implode("\n", array_map(fn ($r) => implode(',', $r), $this->rows()));

        $this->assertStringNotContainsString('51250', $csv); // 50,000 + 2.5%
        $this->assertStringNotContainsString('7687.5', $csv); // 7,500 + 2.5%
        $this->assertStringNotContainsStringIgnoringCase('service', $csv);
        $this->assertStringNotContainsStringIgnoringCase('total', $csv);
    }

    public function test_the_export_is_scoped_to_the_school(): void
    {
        $csv = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/subcategories/export')->streamedContent();
        $this->assertStringNotContainsString('Beta Only Fee', $csv);

        // Another school's export URL is refused for this admin.
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/beta/subcategories/export')->assertNotFound();
    }

    public function test_formula_cells_are_neutralised(): void
    {
        $names = array_column(array_slice($this->rows(), 1), 0);

        $this->assertContains("'=HYPERLINK(\"http://evil\")", $names);
    }

    public function test_anonymous_users_are_sent_to_login(): void
    {
        $this->get('/admin/alpha/subcategories/export')->assertRedirect('/admin/login');
    }

    public function test_the_legacy_url_redirects_to_the_canonical_one(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->get('/s/alpha/subcategories/export')
            ->assertRedirect('/admin/alpha/subcategories/export');
    }
}
