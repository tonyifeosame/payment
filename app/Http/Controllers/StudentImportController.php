<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Services\StudentImportService;
use Illuminate\Http\Request;

/**
 * Bulk-add students to the bound school from a CSV file. The school always comes
 * from the route (EnsureSchoolAdmin has proven it is the signed-in one), never
 * from the file.
 */
class StudentImportController extends Controller
{
    public function __construct(private StudentImportService $imports) {}

    public function create(School $school)
    {
        return view('students.import', ['school' => $school, 'result' => null]);
    }

    public function template(School $school)
    {
        return response($this->imports->template($school), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$school->slug.'-students-template.csv"',
        ]);
    }

    public function store(Request $request, School $school)
    {
        $request->validate([
            'file' => [
                'required', 'file',
                'max:'.StudentImportService::MAX_KILOBYTES,
                'extensions:csv',
                'mimes:csv,txt',
            ],
        ], [
            'file.required' => 'Choose a CSV file to import.',
            'file.uploaded' => 'The file could not be uploaded. It must be a CSV file of at most 10 MB.',
            'file.max' => 'The file is larger than 10 MB. Split it into smaller files and import each one.',
            'file.extensions' => 'The file must be a .csv file.',
            'file.mimes' => 'The file must be a plain-text CSV file.',
        ]);

        $result = $this->imports->import($school, $request->file('file')->getRealPath(), $request);

        if ($result['file_error'] !== null) {
            return redirect()->route('school.students.import.create', ['school' => $school->slug])
                ->withErrors(['file' => $result['file_error']]);
        }

        // Rendered directly rather than flashed: a summary of up to 1,000 rows does
        // not belong in the session. Submitting again is harmless — every row would
        // be reported as already existing.
        return view('students.import', ['school' => $school, 'result' => $result]);
    }
}
