<?php

namespace Tests\Feature;

use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Student;
use App\Services\StudentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Adding students from a CSV file: the template, file checks (type, size, encoding,
 * header, row count), row checks (the Add student rules, the school's own classes
 * and years, duplicate admission numbers), and a summary of imported and failed
 * rows. The school is always the route's; an existing student is never changed.
 */
class StudentImportTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private const HEADER = 'full_name,admission_number,class,status,academic_year,guardian_name,guardian_phone,guardian_email';

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha Academy', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $this->makeSessionWithTerms($this->alpha, '2026/2027');
        ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS 1', 'position' => 1, 'is_active' => true]);
        ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS 2', 'position' => 2, 'is_active' => true]);
        ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'Old Class', 'position' => 3, 'is_active' => false]);
        ClassLevel::create(['school_id' => $this->beta->id, 'name' => 'Beta Class', 'position' => 1, 'is_active' => true]);
    }

    private function csv(array $lines, string $name = 'students.csv', bool $bom = false): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, ($bom ? "\xEF\xBB\xBF" : '').implode("\n", $lines)."\n");
    }

    private function import(UploadedFile $file, string $slug = 'alpha')
    {
        return $this->actingAsSchoolAdmin($this->alpha)->post("/admin/{$slug}/students/import", ['file' => $file]);
    }

    public function test_the_students_page_links_to_the_import(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students')
            ->assertOk()->assertSee('Import CSV')->assertSee('/admin/alpha/students/import', false);
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/import')
            ->assertOk()->assertSee('Download template')->assertSee('full_name');
    }

    public function test_the_template_lists_the_supported_columns(): void
    {
        $response = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/import/template')->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('alpha-students-template.csv', $response->headers->get('Content-Disposition'));
        $lines = explode("\n", trim(ltrim($response->getContent(), "\xEF\xBB\xBF")));
        $this->assertSame(self::HEADER, $lines[0]);
        // The example uses one of the school's own classes and its current year.
        $this->assertStringContainsString('JSS 1', $lines[1]);
        $this->assertStringContainsString('2026/2027', $lines[1]);
    }

    public function test_valid_rows_are_imported_into_this_school(): void
    {
        $this->import($this->csv([
            self::HEADER,
            'Ada Obi,alp/001,jss 1,,2026/2027,Ngozi Obi,08030000000,ngozi@example.com',
            '  Bola   Ade ,ALP/002,JSS 2,graduated,,,,',
        ], bom: true))->assertOk()->assertSee('Import summary')->assertSee('data-imported-count>2<', false);

        $ada = Student::where('admission_number', 'ALP/001')->sole();
        $this->assertSame($this->alpha->id, $ada->school_id);
        $this->assertSame('Ada Obi', $ada->full_name);
        $this->assertSame('JSS 1', $ada->class_name);
        $this->assertSame(ClassLevel::where('school_id', $this->alpha->id)->where('name', 'JSS 1')->value('id'), $ada->class_level_id);
        $this->assertSame(Student::STATUS_ACTIVE, $ada->fresh()->status);
        $this->assertSame('2026/2027', $ada->session->name);
        $this->assertSame('ngozi@example.com', $ada->guardian_email);

        $bola = Student::where('admission_number', 'ALP/002')->sole();
        $this->assertSame('Bola Ade', $bola->full_name);
        $this->assertSame(Student::STATUS_GRADUATED, $bola->status);
    }

    public function test_invalid_rows_are_reported_and_valid_rows_still_import(): void
    {
        $this->import($this->csv([
            self::HEADER,
            'Good One,ALP/010,JSS 1,,,,,',
            ',ALP/011,JSS 1,,,,,',
            'No Number,,JSS 1,,,,,',
            'Wrong Class,ALP/012,JSS 9,,,,,',
            'Inactive Class,ALP/013,Old Class,,,,,',
            'Bad Email,ALP/014,JSS 1,,,,,not-an-email',
            'Bad Status,ALP/015,JSS 1,expelled,,,,',
            'Bad Year,ALP/016,JSS 1,,2099/2100,,,',
            'No Class,ALP/017,,,,,,',
        ]))->assertOk()
            ->assertSee('data-imported-count>1<', false)
            ->assertSee('data-failed-count>8<', false)
            ->assertSeeInOrder(['3', 'Full name is missing.'])
            ->assertSee('Admission number is missing.')
            ->assertSee('Class “JSS 9” is not one of your classes.')
            ->assertSee('Class “Old Class” is inactive.')
            ->assertSee('Guardian email is not a valid email address.')
            ->assertSee('Status must be active, graduated or left.')
            ->assertSee('Academic year “2099/2100” has not been set up for your school.')
            ->assertSee('Class is missing.');

        $this->assertSame(['ALP/010'], Student::pluck('admission_number')->all());
    }

    public function test_duplicate_admission_numbers_in_the_file_are_all_refused(): void
    {
        $this->import($this->csv([
            self::HEADER,
            'First Copy,ALP/020,JSS 1,,,,,',
            'Second Copy,alp/020 ,JSS 2,,,,,',
            'Unique,ALP/021,JSS 1,,,,,',
        ]))->assertOk()->assertSee('appears more than once in this file');

        $this->assertSame(['ALP/021'], Student::pluck('admission_number')->all());
    }

    public function test_existing_students_are_never_overwritten(): void
    {
        $existing = $this->makeStudent($this->alpha, 'ALP/030', 'Original Name', 'JSS 1', ['guardian_phone' => '0801']);

        $this->import($this->csv([
            self::HEADER,
            'Changed Name,ALP/030,JSS 2,left,,,0809,',
        ]))->assertOk()->assertSee('already exists. Existing students are never changed by an import.');

        $existing->refresh();
        $this->assertSame('Original Name', $existing->full_name);
        $this->assertSame('JSS 1', $existing->class_name);
        $this->assertSame('0801', $existing->guardian_phone);
        $this->assertSame(1, Student::count());
    }

    public function test_another_schools_admission_numbers_and_classes_do_not_leak(): void
    {
        $this->makeStudent($this->beta, 'SHARED/1', 'Beta Kid');

        $this->import($this->csv([
            'full_name,admission_number,class,school_id',
            'Alpha Kid,SHARED/1,JSS 1,'.$this->beta->id,
            'Wrong School Class,ALP/040,Beta Class,'.$this->beta->id,
        ]))->assertOk()
            ->assertSee('Class “Beta Class” is not one of your classes.')
            ->assertSee('Ignored column not in the template')
            ->assertSee('school_id');

        // The same admission number at another school is fine, and lands in THIS school.
        $kid = Student::where('full_name', 'Alpha Kid')->sole();
        $this->assertSame($this->alpha->id, $kid->school_id);
        $this->assertSame(1, Student::where('school_id', $this->beta->id)->count());
    }

    public function test_an_admin_cannot_import_into_another_school(): void
    {
        $this->import($this->csv([self::HEADER, 'Sneaky,BET/1,Beta Class,,,,,']), 'beta')->assertNotFound();

        $this->assertSame(0, Student::count());
    }

    public function test_a_school_without_classes_imports_free_text_classes(): void
    {
        $gamma = $this->makeSchool('Gamma School', 'gamma');

        $this->actingAsSchoolAdmin($gamma)->post('/admin/gamma/students/import', [
            'file' => $this->csv(['full_name,admission_number,class', 'Free Text,GAM/2,Primary 3']),
        ])->assertOk()->assertSee('data-imported-count>1<', false);

        $student = Student::sole();
        $this->assertSame($gamma->id, $student->school_id);
        $this->assertSame('Primary 3', $student->class_name);
        $this->assertNull($student->class_level_id);
    }

    public function test_file_level_problems_import_nothing(): void
    {
        $cases = [
            'missing column' => [$this->csv(['full_name,class', 'Ada,JSS 1']), 'missing the required column admission_number'],
            'duplicate header' => [$this->csv(['full_name,admission_number,class,class', 'Ada,A1,JSS 1,JSS 1']), 'appears more than once in the header row'],
            'empty' => [UploadedFile::fake()->createWithContent('e.csv', " \n"), 'is empty'],
            'no rows' => [$this->csv([self::HEADER]), 'header row but no students'],
            'not utf-8' => [UploadedFile::fake()->createWithContent('latin.csv', self::HEADER."\n".mb_convert_encoding('Adé Obi,A1,JSS 1,,,,,', 'ISO-8859-1', 'UTF-8')."\n"), 'not a UTF-8 text CSV'],
        ];

        foreach ($cases as $label => [$file, $message]) {
            $this->import($file)->assertRedirect('/admin/alpha/students/import')->assertSessionHasErrors('file');
            $this->assertStringContainsString($message, session('errors')->first('file'), $label);
        }
        $this->assertSame(0, Student::count());
    }

    public function test_more_than_the_row_limit_is_refused_whole(): void
    {
        $lines = [self::HEADER];
        for ($i = 1; $i <= StudentImportService::MAX_ROWS + 1; $i++) {
            $lines[] = "Student {$i},ALP/{$i},JSS 1,,,,,";
        }

        $this->import($this->csv($lines))->assertSessionHasErrors('file');
        $this->assertStringContainsString('more than 1,000 students', session('errors')->first('file'));
        $this->assertSame(0, Student::count());
    }

    public function test_exactly_the_row_limit_imports(): void
    {
        $lines = [self::HEADER];
        for ($i = 1; $i <= StudentImportService::MAX_ROWS; $i++) {
            $lines[] = "Student {$i},ALP/{$i},JSS 1,,,,,";
        }

        $this->import($this->csv($lines))->assertOk()->assertSee('data-imported-count>1000<', false);
        $this->assertSame(1000, Student::count());
    }

    public function test_file_type_and_size_are_validated(): void
    {
        $this->import(UploadedFile::fake()->create('students.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))
            ->assertSessionHasErrors('file');
        $this->import(UploadedFile::fake()->image('students.png'))->assertSessionHasErrors('file');
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/import', [])->assertSessionHasErrors('file');

        // Just over 10 MB: refused by validation with a clear message.
        $this->import(UploadedFile::fake()->create('big.csv', StudentImportService::MAX_KILOBYTES + 1, 'text/csv'))
            ->assertSessionHasErrors(['file' => 'The file is larger than 10 MB. Split it into smaller files and import each one.']);
        $this->assertSame(0, Student::count());
    }

    public function test_a_csv_of_ten_megabytes_is_accepted(): void
    {
        // A valid file padded to just under 10 MB with long (valid) guardian names
        // and a padding column the import ignores.
        $pad = str_repeat('x', 10_300);
        $lines = ['full_name,admission_number,class,padding'];
        for ($i = 1; $i <= 1000; $i++) {
            $lines[] = "Student {$i},BIG/{$i},JSS 1,{$pad}";
        }
        $file = $this->csv($lines);
        $this->assertGreaterThan(10 * 1000 * 1000, $file->getSize());
        $this->assertLessThanOrEqual(StudentImportService::MAX_KILOBYTES * 1024, $file->getSize());

        $this->import($file)->assertOk()->assertSee('data-imported-count>1000<', false);
        $this->assertSame(1000, Student::count());
    }

    public function test_an_over_large_request_returns_to_the_import_page_with_a_message(): void
    {
        $this->actingAsSchoolAdmin($this->alpha);
        $response = $this->call('POST', '/admin/alpha/students/import', [], [], [], [
            'CONTENT_LENGTH' => (string) (64 * 1024 * 1024 * 1024),
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        ]);

        $response->assertRedirect('http://localhost/admin/alpha/students/import?too_large=1');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/import?too_large=1')
            ->assertSee('larger than 10 MB, so it was not uploaded');
    }

    public function test_an_import_is_audited_with_counts_only(): void
    {
        $this->import($this->csv([self::HEADER, 'Ada Obi,ALP/050,JSS 1,,,,,', 'Bad,,JSS 1,,,,,']));

        $event = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_STUDENTS_IMPORTED)->sole();
        $this->assertSame($this->alpha->id, $event->school_id);
        $this->assertSame(['from' => null, 'to' => 1], $event->changes['imported']);
        $this->assertSame(['from' => null, 'to' => 1], $event->changes['failed']);
        $this->assertStringNotContainsString('Ada', json_encode($event->changes));
    }

    public function test_anonymous_users_are_sent_to_login(): void
    {
        $this->get('/admin/alpha/students/import')->assertRedirect('/admin/login');
        $this->get('/admin/alpha/students/import/template')->assertRedirect('/admin/login');
        $this->post('/admin/alpha/students/import', ['file' => $this->csv([self::HEADER, 'Ada,A1,JSS 1,,,,,'])])->assertRedirect('/admin/login');
        $this->assertSame(0, Student::count());
    }
}
