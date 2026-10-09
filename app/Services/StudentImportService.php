<?php

namespace App\Services;

use App\Http\Requests\StudentRequest;
use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Student;
use App\Support\RecordsSchoolAudit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Adds students to ONE school's roster from a CSV file.
 *
 * The school is the one bound to the route — the file can never name it, and a
 * `school_id` (or any other unknown) column is ignored and reported. Every row is
 * checked before anything is written: the same field rules as the Add student
 * form, the class against this school's own class list (by name, ignoring case),
 * the academic year against this school's existing years, and the admission
 * number against both the rest of the file and the roster. Valid rows are added;
 * invalid rows are reported with their row number and reasons. An existing
 * student is never updated or overwritten.
 */
class StudentImportService
{
    /** Largest accepted file, in kilobytes (10 MB). */
    public const MAX_KILOBYTES = 10240;

    /** Most data rows one file may hold. */
    public const MAX_ROWS = 1000;

    /** Every column the template has, in order. */
    public const COLUMNS = ['full_name', 'admission_number', 'class', 'status', 'academic_year', 'guardian_name', 'guardian_phone', 'guardian_email'];

    /** The columns a file must have. */
    public const REQUIRED_COLUMNS = ['full_name', 'admission_number', 'class'];

    public function __construct(private RecordsSchoolAudit $audit) {}

    /**
     * @return array{imported: int, failed: list<array{row: int, admission_number: string, reasons: list<string>}>, ignored_columns: list<string>, file_error: ?string}
     */
    public function import(School $school, string $path, ?Request $request = null): array
    {
        $result = ['imported' => 0, 'failed' => [], 'ignored_columns' => [], 'file_error' => null];
        $fileError = fn (string $message) => ['file_error' => $message] + $result;

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return $fileError('The file could not be read. Please upload it again.');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        if (! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            return $fileError('The file is not a UTF-8 text CSV. In Excel, use “Save as” → “CSV UTF-8 (Comma delimited)”.');
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream, escape: '');
        if ($header === false || trim(implode('', array_map('strval', $header))) === '') {
            return $fileError('The file is empty. Download the template to see the expected columns.');
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $positions = [];
        foreach ($header as $index => $name) {
            if ($name === '') {
                continue;
            }
            if (isset($positions[$name])) {
                return $fileError('The column “'.$name.'” appears more than once in the header row.');
            }
            if (in_array($name, self::COLUMNS, true)) {
                $positions[$name] = $index;
            } else {
                $result['ignored_columns'][] = $name;
            }
        }
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($positions)));
        if ($missing !== []) {
            return $fileError('The header row is missing the required '.(count($missing) === 1 ? 'column' : 'columns').' '.implode(', ', $missing).'. Download the template to see the expected columns.');
        }

        // Read every data row first: the whole file is checked before anything is added.
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream, escape: '')) !== false) {
            $line++;
            if ($cells === [null] || trim(implode('', array_map('strval', $cells))) === '') {
                continue; // blank line
            }
            if (count($rows) >= self::MAX_ROWS) {
                return $fileError('The file has more than '.number_format(self::MAX_ROWS).' students. Split it into smaller files and import each one.');
            }
            $row = [];
            foreach (self::COLUMNS as $column) {
                $row[$column] = isset($positions[$column]) ? trim((string) ($cells[$positions[$column]] ?? '')) : '';
            }
            $rows[$line] = $row;
        }
        fclose($stream);

        if ($rows === []) {
            return $fileError('The file has a header row but no students.');
        }

        // This school's own reference data, matched case-insensitively.
        $levels = ClassLevel::forSchool($school)->get()->keyBy(fn (ClassLevel $l) => mb_strtolower(trim($l->name)));
        $hasLadder = $levels->isNotEmpty();
        $sessions = AcademicSession::where('school_id', $school->id)->get()->keyBy(fn (AcademicSession $s) => mb_strtolower(trim($s->name)));

        // Admission numbers: duplicates inside the file, and ones already on the roster.
        $numbers = collect($rows)->map(fn ($r) => Student::normalizeAdmissionNumber($r['admission_number']));
        $counts = $numbers->filter()->countBy();
        $existing = $numbers->filter()->unique()->chunk(500)->flatMap(
            fn ($chunk) => Student::forSchool($school)->whereIn('admission_number', $chunk->values())->pluck('admission_number')
        )->flip();

        $valid = [];
        foreach ($rows as $line => $row) {
            $number = $numbers[$line];
            $statusInput = mb_strtolower($row['status']);
            $data = [
                'full_name' => preg_replace('/\s+/u', ' ', $row['full_name']),
                'admission_number' => $number,
                'status' => $statusInput === '' ? Student::STATUS_ACTIVE : $statusInput,
                'guardian_name' => $row['guardian_name'] !== '' ? $row['guardian_name'] : null,
                'guardian_phone' => $row['guardian_phone'] !== '' ? $row['guardian_phone'] : null,
                'guardian_email' => $row['guardian_email'] !== '' ? $row['guardian_email'] : null,
            ];

            $validator = Validator::make($data, StudentRequest::sharedRules(), [
                'full_name.required' => 'Full name is missing.',
                'admission_number.required' => 'Admission number is missing.',
                'status.in' => 'Status must be active, graduated or left.',
                'guardian_email.email' => 'Guardian email is not a valid email address.',
            ]);
            $reasons = $validator->errors()->all();

            if ($number !== '' && ($counts[$number] ?? 0) > 1) {
                $reasons[] = 'Admission number '.$number.' appears more than once in this file.';
            }
            if ($number !== '' && isset($existing[$number])) {
                $reasons[] = 'A student with admission number '.$number.' already exists. Existing students are never changed by an import.';
            }

            $className = $row['class'];
            if ($className === '') {
                $reasons[] = 'Class is missing.';
            } elseif ($hasLadder) {
                $level = $levels->get(mb_strtolower($className));
                if (! $level) {
                    $reasons[] = 'Class “'.$className.'” is not one of your classes.';
                } elseif (! $level->is_active) {
                    $reasons[] = 'Class “'.$className.'” is inactive.';
                } else {
                    $data['class_level_id'] = $level->id;
                    $data['class_name'] = $level->name;
                }
            } elseif (mb_strlen($className) > 100) {
                $reasons[] = 'Class must be at most 100 characters.';
            } else {
                $data['class_name'] = $className;
            }

            if ($row['academic_year'] !== '') {
                $session = $sessions->get(mb_strtolower($row['academic_year']));
                if (! $session) {
                    $reasons[] = 'Academic year “'.$row['academic_year'].'” has not been set up for your school.';
                } else {
                    $data['academic_session_id'] = $session->id;
                }
            }

            if ($reasons !== []) {
                $result['failed'][] = ['row' => $line, 'admission_number' => $number, 'reasons' => array_values(array_unique($reasons))];

                continue;
            }

            $valid[$line] = $data;
        }

        // Add the valid rows. Each insert runs in its own savepoint, so a row the
        // database refuses (another admin added the same admission number a moment
        // ago) fails on its own — never half-created — and the rest still commit.
        DB::transaction(function () use ($school, $valid, &$result, $request) {
            foreach ($valid as $line => $data) {
                try {
                    DB::transaction(fn () => $school->students()->create($data));
                    $result['imported']++;
                } catch (QueryException) {
                    $result['failed'][] = ['row' => $line, 'admission_number' => $data['admission_number'], 'reasons' => ['A student with admission number '.$data['admission_number'].' already exists. Existing students are never changed by an import.']];
                }
            }

            if ($result['imported'] > 0) {
                $this->audit->record($school, SchoolAuditEvent::ACTION_STUDENTS_IMPORTED, null, null, [
                    'imported' => ['from' => null, 'to' => $result['imported']],
                    'failed' => ['from' => null, 'to' => count($result['failed'])],
                ], request: $request);
            }
        });

        usort($result['failed'], fn ($a, $b) => $a['row'] <=> $b['row']);

        return $result;
    }

    /** The downloadable template: the header row and one example student. */
    public function template(School $school): string
    {
        $class = ClassLevel::forSchool($school)->active()->ordered()->value('name') ?? 'JSS 1';
        $year = $school->currentTerm?->session?->name ?? '';

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS, escape: '');
        fputcsv($out, ['Ada Obi', 'ADM/2026/001', $class, 'active', $year, 'Ngozi Obi', '08030000000', 'parent@example.com'], escape: '');
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
