<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentRequest;
use App\Models\School;
use App\Models\Student;
use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * Tenant-scoped student roster.
 *
 * Every query starts from the bound school. {student} resolves through
 * scopeBindings() on the route group, so a student belonging to another school
 * 404s during binding; assertBelongsToSchool() is the backstop behind that.
 */
class StudentController extends Controller
{
    private function assertBelongsToSchool(School $school, Student $student): void
    {
        if ((int) $student->school_id !== (int) $school->id) {
            abort(404);
        }
    }

    public function index(Request $request, School $school)
    {
        $q = trim((string) $request->input('q', ''));
        $class = trim((string) $request->input('class', ''));

        $students = Student::forSchool($school)
            ->with('session')
            ->search($q)
            ->when($class !== '', fn ($query) => $query->where('class_name', $class))
            ->orderBy('class_name')
            ->orderBy('full_name')
            ->paginate(25)
            ->withQueryString();

        $classes = Student::forSchool($school)
            ->distinct()
            ->orderBy('class_name')
            ->pluck('class_name');

        return view('students.index', compact('school', 'students', 'q', 'class', 'classes'));
    }

    public function create(School $school)
    {
        return view('students.create', [
            'school' => $school,
            'sessions' => $school->academicSessions()->get(),
        ]);
    }

    public function store(StudentRequest $request, School $school)
    {
        $school->students()->create($request->validated());

        return redirect()->route('school.students.index', ['school' => $school->slug])
            ->with('success', 'Student added.');
    }

    public function show(School $school, Student $student)
    {
        $this->assertBelongsToSchool($school, $student);

        // The student's own payment history, newest first. Scoped twice: by the
        // student (already proven to be this school's) and by the school itself.
        $transactions = Transaction::forSchool($school)
            ->where('student_id', $student->id)
            ->with(['category', 'academicTerm.session'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $totalPaid = (float) Transaction::forSchool($school)
            ->successful()
            ->where('student_id', $student->id)
            ->sum('fee_amount');

        return view('students.show', compact('school', 'student', 'transactions', 'totalPaid'));
    }

    public function edit(School $school, Student $student)
    {
        $this->assertBelongsToSchool($school, $student);

        return view('students.edit', [
            'school' => $school,
            'student' => $student,
            'sessions' => $school->academicSessions()->get(),
        ]);
    }

    public function update(StudentRequest $request, School $school, Student $student)
    {
        $this->assertBelongsToSchool($school, $student);

        $student->update($request->validated());

        return redirect()->route('school.students.index', ['school' => $school->slug])
            ->with('success', 'Student updated.');
    }
}
