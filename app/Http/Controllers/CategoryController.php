<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\RecordsSchoolAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    /**
     * Fail closed if a category does not belong to the acting school.
     *
     * Route-level scopeBindings() already resolves categories through the school's
     * own relationship, so this should never fire. It is kept as a deliberate
     * backstop so a future route registered without scopeBindings cannot silently
     * reintroduce cross-tenant access.
     */
    private function assertBelongsToSchool(School $school, Category $category): void
    {
        if ((int) $category->school_id !== (int) $school->id) {
            abort(404);
        }
    }

    /**
     * The school's category with this name in any spelling — case, spacing,
     * punctuation and plurals ignored (Category::normalizeName) — or null.
     */
    public static function findByName(School $school, string $name, ?int $ignoreId = null): ?Category
    {
        $wanted = Category::normalizeName($name);

        return Category::where('school_id', $school->id)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->orderBy('id')
            ->get()
            ->first(fn (Category $c) => Category::normalizeName($c->name) === $wanted);
    }

    /**
     * Name rules shared by create and rename: required, not a spelling of the
     * built-in "School Fees", and not a near duplicate of another category.
     */
    private function validatedName(Request $request, School $school, ?Category $ignore = null): string
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);
        $name = trim((string) $request->input('name'));

        if (Category::isSchoolFeesName($name)) {
            throw ValidationException::withMessages([
                'name' => '“School Fees” is built in. Choose “School fees” when you add a fee, or give this category another name.',
            ]);
        }

        if ($clash = self::findByName($school, $name, $ignore?->id)) {
            throw ValidationException::withMessages([
                'name' => 'You already have a category called “'.$clash->name.'”.',
            ]);
        }

        return $name;
    }

    /**
     * Tenant-aware listing for a given school.
     */
    public function indexSchool(School $school)
    {
        Category::schoolFeesFor($school);

        // The built-in School Fees category first, then the school's own.
        $categories = Category::where('school_id', $school->id)->withCount('subcategories')
            ->orderByRaw('CASE WHEN system_key IS NULL THEN 1 ELSE 0 END')->orderBy('id')->get();

        return view('categories.index', compact('categories', 'school'));
    }

    /**
     * Tenant-aware creation for a given school.
     */
    public function storeSchool(Request $request, School $school)
    {
        $name = $this->validatedName($request, $school);

        Category::create([
            'name' => $name,
            'school_id' => $school->id,
        ]);

        return redirect()->route('school.categories.index', ['school' => $school->slug])
            ->with('success', 'Category created successfully!');
    }

    public function editSchool(School $school, Category $category)
    {
        $this->assertBelongsToSchool($school, $category);

        if ($category->isSystem()) {
            return $this->builtIn($school);
        }

        return view('categories.edit', compact('school', 'category'));
    }

    public function updateSchool(Request $request, School $school, Category $category)
    {
        $this->assertBelongsToSchool($school, $category);

        if ($category->isSystem()) {
            return $this->builtIn($school);
        }

        $category->update([
            'name' => $this->validatedName($request, $school, $category),
        ]);

        return redirect()->route('school.categories.index', ['school' => $school->slug])
            ->with('success', 'Category updated successfully!');
    }

    public function destroySchool(Request $request, School $school, Category $category, RecordsSchoolAudit $audit)
    {
        $this->assertBelongsToSchool($school, $category);

        if ($category->isSystem()) {
            return $this->builtIn($school);
        }

        // Destructive and irreversible, so the audit event is the only remaining
        // record of what was removed (M7, Tier 2).
        DB::transaction(function () use ($category, $school, $audit, $request) {
            $name = $category->name;

            $category->delete();

            $audit->record($school, SchoolAuditEvent::ACTION_CATEGORY_DELETED, 'category', $category->id, [
                'name' => ['from' => $name, 'to' => null],
            ], request: $request);
        });

        return redirect()->route('school.categories.index', ['school' => $school->slug])
            ->with('success', 'Category deleted successfully.');
    }

    /** The built-in School Fees category cannot be renamed or deleted. */
    private function builtIn(School $school)
    {
        return redirect()->route('school.categories.index', ['school' => $school->slug])
            ->with('error', '“School Fees” is built in and cannot be renamed or deleted. Its fees can be edited or deleted on the Fees page.');
    }
}
