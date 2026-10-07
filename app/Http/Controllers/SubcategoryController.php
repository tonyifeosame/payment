<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\Category;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Subcategory;
use App\Services\AcademicPeriodService;
use App\Support\RecordsSchoolAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /**
     * Resolve the class levels a fee is assigned to, every one of them the acting
     * school's, or 404 — a foreign class level id fails closed like any other.
     *
     * @return array<int, int>
     */
    private function resolveOwnedClassLevelIds(School $school, ?array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids ?? [])));
        if ($ids === []) {
            return [];
        }

        $owned = ClassLevel::forSchool($school)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($owned) !== count($ids)) {
            abort(404);
        }
        sort($owned);

        return $owned;
    }

    /**
     * The main-fee (tuition) rules, checked inside the saving transaction with the
     * school row locked so two concurrent saves cannot both pass:
     *
     *   - a main fee must be assigned to at least one class: unassigned, it would
     *     belong to no class (and is payable by no one — Subcategory::isPayableForStudent);
     *   - a class has at most ONE main fee per term (or one general main fee), so the
     *     fee a student's class resolves to is never ambiguous.
     *
     * @param  array<int, int>  $classLevelIds
     */
    private function assertMainFeeRules(School $school, bool $isTuition, ?AcademicTerm $term, array $classLevelIds, ?int $ignoreFeeId = null): void
    {
        if (! $isTuition) {
            return;
        }

        if ($classLevelIds === []) {
            throw ValidationException::withMessages([
                'class_level_ids' => 'Choose the class or classes this main fee is for.',
            ]);
        }

        School::whereKey($school->id)->lockForUpdate()->first();

        $clash = Subcategory::with(['classLevels' => fn ($q) => $q->whereIn('class_levels.id', $classLevelIds)])
            ->where('school_id', $school->id)
            ->where('is_tuition', true)
            ->when($ignoreFeeId !== null, fn ($q) => $q->whereKeyNot($ignoreFeeId))
            ->when($term, fn ($q) => $q->where('academic_term_id', $term->id), fn ($q) => $q->whereNull('academic_term_id'))
            ->whereHas('classLevels', fn ($q) => $q->whereIn('class_levels.id', $classLevelIds))
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'class_level_ids' => sprintf(
                    '%s already %s a main fee for this term: "%s". A class can have only one main fee per term — untick %s, or make one of the fees an ordinary fee.',
                    $clash->classLevels->pluck('name')->implode(', '),
                    $clash->classLevels->count() === 1 ? 'has' : 'have',
                    $clash->name,
                    $clash->classLevels->count() === 1 ? 'that class' : 'those classes',
                ),
            ]);
        }
    }

    /** Assign the fee to exactly these class levels; each row carries the school for tenant scoping. */
    private function syncClassLevels(School $school, Subcategory $fee, array $classLevelIds): void
    {
        $fee->classLevels()->sync(array_fill_keys($classLevelIds, ['school_id' => $school->id]));
    }

    /** The assignment as an audit value: class names in ladder order, or null for "all classes". */
    private function classLevelsForAudit(Subcategory $fee): ?string
    {
        $names = $fee->classLevels()->orderBy('class_levels.position')->orderBy('class_levels.id')->pluck('name');

        return $names->isEmpty() ? null : $names->implode(', ');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'academic_term_id' => 'nullable|integer',
            'allows_quantity' => 'nullable|boolean',
            'is_tuition' => 'nullable|boolean',
            'class_level_ids' => 'nullable|array',
            'class_level_ids.*' => 'integer',
        ]);
    }

    /**
     * Tenant-aware listing for a given school.
     */
    public function indexSchool(School $school)
    {
        // Presentation order only: by category, then term (general fees first), then name.
        $subcategories = Subcategory::with(['category', 'academicTerm.session', 'classLevels'])
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
        $classLevels = $school->classLevels()->get();

        return view('subcategories.create', compact('categories', 'school', 'terms', 'classLevels'));
    }

    /**
     * Tenant-aware store for a given school.
     */
    public function storeSchool(Request $request, School $school, RecordsSchoolAudit $audit)
    {
        $data = $this->validated($request);

        $category = $this->resolveOwnedCategory($school, $data['category_id']);
        $term = $this->resolveOwnedTerm($school, $data['academic_term_id'] ?? null);
        $classLevelIds = $this->resolveOwnedClassLevelIds($school, $data['class_level_ids'] ?? null);

        // Fee row and audit row commit together (M7): the price is what parents
        // are charged, so a change to it must never be recorded without the change
        // itself, or the change happen without a record.
        DB::transaction(function () use ($school, $category, $term, $data, $classLevelIds, $audit, $request) {
            $this->assertMainFeeRules($school, (bool) ($data['is_tuition'] ?? false), $term, $classLevelIds);

            $fee = Subcategory::create([
                'category_id' => $category->id,
                'name' => $data['name'],
                'price' => $data['price'] ?? null,
                'school_id' => $school->id,
                'academic_term_id' => $term?->id,
                'allows_quantity' => (bool) ($data['allows_quantity'] ?? false),
                'is_tuition' => (bool) ($data['is_tuition'] ?? false),
            ]);
            $this->syncClassLevels($school, $fee, $classLevelIds);

            $audit->record($school, SchoolAuditEvent::ACTION_FEE_CREATED, 'subcategory', $fee->id, [
                'name' => ['from' => null, 'to' => $fee->name],
                'price' => ['from' => null, 'to' => $fee->price],
                'allows_quantity' => ['from' => null, 'to' => $fee->allows_quantity],
                'is_tuition' => ['from' => null, 'to' => $fee->is_tuition],
                'class_levels' => ['from' => null, 'to' => $this->classLevelsForAudit($fee)],
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
        $classLevels = $school->classLevels()->get();
        $subcategory->load('classLevels');

        return view('subcategories.edit', compact('school', 'subcategory', 'categories', 'terms', 'classLevels'));
    }

    public function updateSchool(Request $request, School $school, Subcategory $subcategory, RecordsSchoolAudit $audit)
    {
        $this->assertBelongsToSchool($school, $subcategory);

        $data = $this->validated($request);

        $category = $this->resolveOwnedCategory($school, $data['category_id']);
        $term = $this->resolveOwnedTerm($school, $data['academic_term_id'] ?? null);
        $classLevelIds = $this->resolveOwnedClassLevelIds($school, $data['class_level_ids'] ?? null);

        $before = [
            'name' => $subcategory->name,
            'price' => $subcategory->price,
            'category_id' => $subcategory->category_id,
            'academic_term_id' => $subcategory->academic_term_id,
            'allows_quantity' => $subcategory->allows_quantity,
            'is_tuition' => $subcategory->is_tuition,
            'class_levels' => $this->classLevelsForAudit($subcategory),
        ];

        DB::transaction(function () use ($subcategory, $school, $category, $term, $data, $classLevelIds, $before, $audit, $request) {
            $this->assertMainFeeRules($school, (bool) ($data['is_tuition'] ?? false), $term, $classLevelIds, $subcategory->id);

            $subcategory->update([
                'category_id' => $category->id,
                'name' => $data['name'],
                'price' => $data['price'] ?? null,
                'academic_term_id' => $term?->id,
                'allows_quantity' => (bool) ($data['allows_quantity'] ?? false),
                'is_tuition' => (bool) ($data['is_tuition'] ?? false),
            ]);
            $this->syncClassLevels($school, $subcategory, $classLevelIds);

            // Only the fields that actually moved (M7) — an unchanged price is not
            // a price change, even when the form resubmits it.
            $changes = $audit->diff($before, [
                'name' => $subcategory->name,
                'price' => $subcategory->price,
                'category_id' => $subcategory->category_id,
                'academic_term_id' => $subcategory->academic_term_id,
                'allows_quantity' => $subcategory->allows_quantity,
                'is_tuition' => $subcategory->is_tuition,
                'class_levels' => $this->classLevelsForAudit($subcategory),
            ], ['name', 'price', 'category_id', 'academic_term_id', 'allows_quantity', 'is_tuition', 'class_levels']);

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
                'allows_quantity' => ['from' => $subcategory->allows_quantity, 'to' => null],
            ];

            $subcategory->delete();

            $audit->record($school, SchoolAuditEvent::ACTION_FEE_DELETED, 'subcategory', $subcategory->id, $deleted, request: $request);
        });

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Subcategory deleted successfully.');
    }
}
