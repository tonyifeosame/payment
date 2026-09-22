<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\RecordsSchoolAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * Tenant-aware listing for a given school.
     */
    public function indexSchool(School $school)
    {
        $categories = Category::where('school_id', $school->id)->withCount('subcategories')->get();

        return view('categories.index', compact('categories', 'school'));
    }

    /**
     * Tenant-aware creation for a given school.
     */
    public function storeSchool(Request $request, School $school)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Category::create([
            'name' => $request->name,
            'school_id' => $school->id,
        ]);

        return redirect()->route('school.categories.index', ['school' => $school->slug])
            ->with('success', 'Category created successfully!');
    }

    public function editSchool(School $school, Category $category)
    {
        $this->assertBelongsToSchool($school, $category);

        return view('categories.edit', compact('school', 'category'));
    }

    public function updateSchool(Request $request, School $school, Category $category)
    {
        $this->assertBelongsToSchool($school, $category);

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $category->update([
            'name' => $request->name,
        ]);

        return redirect()->route('school.categories.index', ['school' => $school->slug])
            ->with('success', 'Category updated successfully!');
    }

    public function destroySchool(Request $request, School $school, Category $category, RecordsSchoolAudit $audit)
    {
        $this->assertBelongsToSchool($school, $category);

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
}
