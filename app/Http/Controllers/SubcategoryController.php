<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
use App\Services\AcademicPeriodService;
use Illuminate\Http\Request;

class SubcategoryController extends Controller
{
    /**
     * Fail closed if a subcategory does not belong to the acting school.
     * See CategoryController::assertBelongsToSchool() for why this backstop exists.
     */
    private function assertBelongsToSchool(School $school, Subcategory $subcategory): void
    {
        if ((int) $subcategory->school_id !== (int) $school->id) {
            abort(404);
        }
    }

    /**
     * Resolve a category the acting school actually owns, or 404.
     * Guards against a chosen category_id that passes `exists:categories,id`
     * but belongs to another school.
     */
    private function resolveOwnedCategory(School $school, $categoryId): Category
    {
        return Category::where('school_id', $school->id)->findOrFail($categoryId);
    }

    /**
     * Resolve the term a fee is charged for, or null for a general fee.
     * A term id that is not this school's is a 404, like every other foreign id.
     */
    private function resolveOwnedTerm(School $school, $termId): ?AcademicTerm
    {
        if ($termId === null || $termId === '') {
            return null;
        }

        return AcademicTerm::where('school_id', $school->id)->findOrFail($termId);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'academic_term_id' => 'nullable|integer',
        ]);
    }

    /**
     * Tenant-aware listing for a given school.
     */
    public function indexSchool(School $school)
    {
        $subcategories = Subcategory::with(['category', 'academicTerm.session'])
            ->where('school_id', $school->id)
            ->get();
        $categories = Category::where('school_id', $school->id)->get();

        return view('subcategories.index', compact('subcategories', 'categories', 'school'));
    }

    /**
     * Tenant-aware create view.
     */
    public function createSchool(School $school, AcademicPeriodService $periods)
    {
        $categories = Category::where('school_id', $school->id)->get();
        $terms = $periods->termsForSchool($school);

        return view('subcategories.create', compact('categories', 'school', 'terms'));
    }

    /**
     * Tenant-aware store for a given school.
     */
    public function storeSchool(Request $request, School $school)
    {
        $data = $this->validated($request);

        $category = $this->resolveOwnedCategory($school, $data['category_id']);
        $term = $this->resolveOwnedTerm($school, $data['academic_term_id'] ?? null);

        Subcategory::create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'price' => $data['price'] ?? null,
            'school_id' => $school->id,
            'academic_term_id' => $term?->id,
        ]);

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Subcategory created successfully.');
    }

    public function editSchool(School $school, Subcategory $subcategory, AcademicPeriodService $periods)
    {
        $this->assertBelongsToSchool($school, $subcategory);

        $categories = Category::where('school_id', $school->id)->get();
        $terms = $periods->termsForSchool($school);

        return view('subcategories.edit', compact('school', 'subcategory', 'categories', 'terms'));
    }

    public function updateSchool(Request $request, School $school, Subcategory $subcategory)
    {
        $this->assertBelongsToSchool($school, $subcategory);

        $data = $this->validated($request);

        $category = $this->resolveOwnedCategory($school, $data['category_id']);
        $term = $this->resolveOwnedTerm($school, $data['academic_term_id'] ?? null);

        $subcategory->update([
            'category_id' => $category->id,
            'name' => $data['name'],
            'price' => $data['price'] ?? null,
            'academic_term_id' => $term?->id,
        ]);

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Subcategory updated successfully.');
    }

    public function destroySchool(School $school, Subcategory $subcategory)
    {
        $this->assertBelongsToSchool($school, $subcategory);

        $subcategory->delete();

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Subcategory deleted successfully.');
    }
}
