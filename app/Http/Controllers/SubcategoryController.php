<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Category;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Subcategory;
use App\Services\AcademicPeriodService;
use App\Support\RecordsSchoolAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        // Presentation order only: by category, then term (general fees first), then name.
        $subcategories = Subcategory::with(['category', 'academicTerm.session'])
            ->where('school_id', $school->id)
            ->get()
            ->sortBy([
                fn ($a, $b) => strcasecmp($a->category->name ?? '', $b->category->name ?? ''),
                fn ($a, $b) => strcmp($a->academicTerm?->session?->name ?? '', $b->academicTerm?->session?->name ?? ''),
                fn ($a, $b) => ($a->academicTerm?->number ?? 0) <=> ($b->academicTerm?->number ?? 0),
                fn ($a, $b) => strcasecmp($a->name, $b->name),
            ])
            ->values();
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
    public function storeSchool(Request $request, School $school, RecordsSchoolAudit $audit)
    {
        $data = $this->validated($request);

        $category = $this->resolveOwnedCategory($school, $data['category_id']);
        $term = $this->resolveOwnedTerm($school, $data['academic_term_id'] ?? null);

        // Fee row and audit row commit together (M7): the price is what parents
        // are charged, so a change to it must never be recorded without the change
        // itself, or the change happen without a record.
        DB::transaction(function () use ($school, $category, $term, $data, $audit, $request) {
            $fee = Subcategory::create([
                'category_id' => $category->id,
                'name' => $data['name'],
                'price' => $data['price'] ?? null,
                'school_id' => $school->id,
                'academic_term_id' => $term?->id,
            ]);

            $audit->record($school, SchoolAuditEvent::ACTION_FEE_CREATED, 'subcategory', $fee->id, [
                'name' => ['from' => null, 'to' => $fee->name],
                'price' => ['from' => null, 'to' => $fee->price],
            ], request: $request);
        });

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

    public function updateSchool(Request $request, School $school, Subcategory $subcategory, RecordsSchoolAudit $audit)
    {
        $this->assertBelongsToSchool($school, $subcategory);

        $data = $this->validated($request);

        $category = $this->resolveOwnedCategory($school, $data['category_id']);
        $term = $this->resolveOwnedTerm($school, $data['academic_term_id'] ?? null);

        $before = [
            'name' => $subcategory->name,
            'price' => $subcategory->price,
            'category_id' => $subcategory->category_id,
            'academic_term_id' => $subcategory->academic_term_id,
        ];

        DB::transaction(function () use ($subcategory, $school, $category, $term, $data, $before, $audit, $request) {
            $subcategory->update([
                'category_id' => $category->id,
                'name' => $data['name'],
                'price' => $data['price'] ?? null,
                'academic_term_id' => $term?->id,
            ]);

            // Only the fields that actually moved (M7) — an unchanged price is not
            // a price change, even when the form resubmits it.
            $changes = $audit->diff($before, [
                'name' => $subcategory->name,
                'price' => $subcategory->price,
                'category_id' => $subcategory->category_id,
                'academic_term_id' => $subcategory->academic_term_id,
            ], ['name', 'price', 'category_id', 'academic_term_id']);

            if ($changes !== []) {
                $audit->record($school, SchoolAuditEvent::ACTION_FEE_UPDATED, 'subcategory', $subcategory->id, $changes, request: $request);
            }
        });

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Subcategory updated successfully.');
    }

    public function destroySchool(Request $request, School $school, Subcategory $subcategory, RecordsSchoolAudit $audit)
    {
        $this->assertBelongsToSchool($school, $subcategory);

        // The deleted row leaves nothing behind, so the audit event IS the record
        // of what the fee was (M7, Tier 2).
        DB::transaction(function () use ($subcategory, $school, $audit, $request) {
            $deleted = [
                'name' => ['from' => $subcategory->name, 'to' => null],
                'price' => ['from' => $subcategory->price, 'to' => null],
            ];

            $subcategory->delete();

            $audit->record($school, SchoolAuditEvent::ACTION_FEE_DELETED, 'subcategory', $subcategory->id, $deleted, request: $request);
        });

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Subcategory deleted successfully.');
    }
}
