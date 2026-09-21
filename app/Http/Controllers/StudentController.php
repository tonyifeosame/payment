<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentRequest;
use App\Models\School;
use App\Models\Student;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        // `class` is a class level id from the ladder, or - for students still on a
        // free-text class - the exact legacy name. Both are matched within this school only.
        $class = trim((string) $request->input('class', ''));
        $status = (string) $request->input('status', Student::STATUS_ACTIVE);
        if ($status !== 'all' && ! array_key_exists($status, Student::STATUS_LABELS)) {
            $status = Student::STATUS_ACTIVE;
        }

        $students = Student::forSchool($school)
            ->with(['session', 'classLevel'])
            ->search($q)
            ->when($class !== '', fn ($query) => ctype_digit($class)
                ? $query->where('class_level_id', (int) $class)
                : $query->whereNull('class_level_id')->where('class_name', $class))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->rosterOrder()
            ->paginate(25)
            ->withQueryString();

        $levels = $school->classLevels()->get();
        $legacyClasses = Student::forSchool($school)
            ->whereNull('class_level_id')
            ->distinct()
            ->orderBy('class_name')
            ->pluck('class_name');

        $counts = [
            'total' => Student::forSchool($school)->count(),
            'active' => Student::forSchool($school)->active()->count(),
        ];

        return view('students.index', compact('school', 'students', 'q', 'class', 'status', 'levels', 'legacyClasses', 'counts'));
    }

    public function create(School $school)
    {
        return view('students.create', [
            'school' => $school,
            'sessions' => $school->academicSessions()->get(),
            'levels' => $school->classLevels()->active()->get(),
        ]);
    }

    public function store(StudentRequest $request, School $school)
    {
        $school->students()->create($request->studentAttributes());

        return redirect()->route('school.students.index', ['school' => $school->slug])
            ->with('success', 'Student added.');
    }

    public function show(Request $request, School $school, Student $student)
    {
        $this->assertBelongsToSchool($school, $student);

        // The student's own payment history, newest first. Scoped twice: by the
        // student (already proven to be this school's) and by the school itself.
        // Successful and pending payments are the history; failed/mismatched
        // attempts are kept out unless asked for, so they never look like money.
        $history = Transaction::forSchool($school)->where('student_id', $student->id);
        $showAttempts = $request->boolean('attempts');
        $otherAttempts = (clone $history)->whereNotIn('status', [Transaction::STATUS_SUCCESS, 'pending'])->count();

        $transactions = (clone $history)
            ->when(! $showAttempts, fn ($q) => $q->whereIn('status', [Transaction::STATUS_SUCCESS, 'pending']))
            ->with(['category', 'academicTerm.session'])
            // Same order as the transactions list; the id tiebreak keeps pages stable.
            ->orderByDesc(DB::raw(Transaction::paidAtExpression()))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $totalPaid = (float) Transaction::forSchool($school)
            ->successful()
            ->where('student_id', $student->id)
            ->sum('fee_amount');

        return view('students.show', compact('school', 'student', 'transactions', 'totalPaid', 'showAttempts', 'otherAttempts'));
    }

    public function edit(School $school, Student $student)
    {
        $this->assertBelongsToSchool($school, $student);

        // Active levels, plus the student's own level even if it has been deactivated.
        $levels = $school->classLevels()
            ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $student->class_level_id))
            ->get();

        return view('students.edit', [
            'school' => $school,
            'student' => $student,
            'sessions' => $school->academicSessions()->get(),
            'levels' => $levels,
        ]);
    }

    public function update(StudentRequest $request, School $school, Student $student)
    {
        $this->assertBelongsToSchool($school, $student);

        $student->update($request->studentAttributes());

        return redirect()->route('school.students.index', ['school' => $school->slug])
            ->with('success', 'Student updated.');
    }
}
