<?php

namespace App\Http\Controllers;

use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The school's class ladder, tenant-scoped.
 *
 * {classLevel} is scope-bound through School::classLevels() on the route group,
 * so another school's id 404s during binding; assertOwn() is the backstop.
 * Order is edited with move up/down (keyboard- and touch-friendly); positions are
 * renumbered 1..n after every change so they never drift.
 */
class ClassLevelController extends Controller
{
    private function assertOwn(School $school, ClassLevel $level): void
    {
        if ((int) $level->school_id !== (int) $school->id) {
            abort(404);
        }
    }

    private function nameRules(School $school, ?ClassLevel $ignore = null): array
    {
        $unique = Rule::unique('class_levels', 'name')->where(fn ($q) => $q->where('school_id', $school->id));
        if ($ignore) {
            $unique->ignore($ignore->id);
        }

        return ['required', 'string', 'max:100', $unique];
    }

    public function index(School $school)
    {
        $levels = $school->classLevels()->withCount('students')->get();

        // Students whose class is still free text (pre-migration rows, or rows created
        // before the school set up its ladder). Mapping them is an explicit admin
        // action per name — never a guess based on the string.
        $unassigned = Student::forSchool($school)
            ->whereNull('class_level_id')
            ->select('class_name', DB::raw('COUNT(*) as students_count'))
            ->groupBy('class_name')
            ->orderBy('class_name')
            ->get();

        return view('students.classes.index', [
            'school' => $school,
            'levels' => $levels,
            'unassigned' => $unassigned,
        ]);
    }

    public function store(Request $request, School $school)
    {
        $data = $request->validate(['name' => $this->nameRules($school)], [
            'name.unique' => 'Your school already has a class with this name.',
        ]);

        $position = ((int) $school->classLevels()->max('position')) + 1;
        $school->classLevels()->create(['name' => trim($data['name']), 'position' => $position, 'is_active' => true]);

        return $this->back($school, 'Class "'.trim($data['name']).'" added at the end of the ladder.');
    }

    /** Rename and/or (de)activate. Students keep their level; the display name follows the rename. */
    public function update(Request $request, School $school, ClassLevel $classLevel)
    {
        $this->assertOwn($school, $classLevel);

        $data = $request->validate([
            'name' => $this->nameRules($school, $classLevel),
            'is_active' => ['nullable', 'boolean'],
        ], ['name.unique' => 'Your school already has a class with this name.']);

        DB::transaction(function () use ($school, $classLevel, $data) {
            $classLevel->update(['name' => trim($data['name']), 'is_active' => (bool) ($data['is_active'] ?? false)]);
            // class_name is the snapshot receipts and the payment page read; keep it in step.
            Student::forSchool($school)->where('class_level_id', $classLevel->id)->update(['class_name' => $classLevel->name]);
        });

        return $this->back($school, 'Class "'.$classLevel->name.'" updated.');
    }

    /** Swap with the neighbour above or below, then renumber. */
    public function move(Request $request, School $school, ClassLevel $classLevel)
    {
        $this->assertOwn($school, $classLevel);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        DB::transaction(function () use ($school, $classLevel, $direction) {
            $levels = $school->classLevels()->lockForUpdate()->get()->values();
            $index = $levels->search(fn (ClassLevel $l) => $l->id === $classLevel->id);
            $swap = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || $swap < 0 || $swap >= $levels->count()) {
                return; // already at the edge: nothing to do
            }
            $reordered = $levels->all();
            [$reordered[$index], $reordered[$swap]] = [$reordered[$swap], $reordered[$index]];
            foreach ($reordered as $i => $level) {
                if ($level->position !== $i + 1) {
                    $level->update(['position' => $i + 1]);
                }
            }
        });

        return $this->back($school, 'Class order updated.');
    }

    /** Only a class no student has ever been in may be deleted; otherwise deactivate it. */
    public function destroy(School $school, ClassLevel $classLevel)
    {
        $this->assertOwn($school, $classLevel);

        if (Student::forSchool($school)->where('class_level_id', $classLevel->id)->exists()) {
            return $this->back($school, null, 'Students are in "'.$classLevel->name.'". Move them first, or deactivate the class instead.');
        }

        DB::transaction(function () use ($school, $classLevel) {
            $classLevel->delete();
            foreach ($school->classLevels()->get()->values() as $i => $level) {
                if ($level->position !== $i + 1) {
                    $level->update(['position' => $i + 1]);
                }
            }
        });

        return $this->back($school, 'Class "'.$classLevel->name.'" removed.');
    }

    /**
     * Map every student whose free-text class is exactly $class_name (and who has no
     * level yet) to a level: an existing one, or a new one created with that name.
     * Explicit and exact — the admin chose the mapping; nothing is matched loosely.
     */
    public function assignLegacy(Request $request, School $school)
    {
        $data = $request->validate([
            'class_name' => ['required', 'string', 'max:100'],
            'class_level_id' => [
                'required',
                function ($attribute, $value, $fail) use ($school) {
                    if ($value !== 'new' && ! ClassLevel::forSchool($school)->whereKey($value)->exists()) {
                        $fail('Choose one of your classes.');
                    }
                },
            ],
        ]);

        $name = $data['class_name'];
        $count = 0;

        DB::transaction(function () use ($school, $data, $name, &$count) {
            if ($data['class_level_id'] === 'new') {
                $existing = ClassLevel::forSchool($school)->where('name', $name)->first();
                $level = $existing ?? $school->classLevels()->create([
                    'name' => $name,
                    'position' => ((int) $school->classLevels()->max('position')) + 1,
                    'is_active' => true,
                ]);
            } else {
                $level = ClassLevel::forSchool($school)->findOrFail((int) $data['class_level_id']);
            }

            $count = Student::forSchool($school)
                ->whereNull('class_level_id')
                ->where('class_name', $name)
                ->update(['class_level_id' => $level->id, 'class_name' => $level->name]);
        });

        return $this->back($school, $count.' '.str('student')->plural($count).' with class "'.$name.'" assigned.');
    }

    private function back(School $school, ?string $success, ?string $error = null)
    {
        $redirect = redirect()->route('school.students.classes.index', ['school' => $school->slug]);

        return $error ? $redirect->with('error', $error) : $redirect->with('success', $success);
    }
}
