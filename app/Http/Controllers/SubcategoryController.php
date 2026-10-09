<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Category;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Subcategory;
use App\Services\AcademicPeriodService;
use App\Support\BusinessTime;
use App\Support\CsvCell;
use App\Support\RecordsSchoolAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubcategoryController extends Controller
{
    public const MESSAGE_TYPE_LOCKED = 'This fee already has payment records, so it cannot be changed between School fees and an additional fee. Create a new fee of the type you need instead.';

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

    /**
     * Does this fee have any payment record at all? Every row counts, whatever its
     * status (success, pending, failed, mismatch, voided) or source (online or cash).
     */
    private function hasPaymentRecords(Subcategory $fee): bool
    {
        return $fee->transactions()->exists();
    }

    /**
     * A fee with payment records keeps its type. Whether a past payment counts as
     * school fees paid for its term can depend on the fee's type
     * (Transaction::scopeTuitionPaid), so switching it would quietly un-pay a term —
     * or, the other way, turn an additional fee bought in a term into that term's
     * school fees. The admin creates a new fee instead; no payment row is touched.
     */
    private function assertTypeUnchangedOnceUsed(Subcategory $fee, bool $isTuition): void
    {
        if ((bool) $fee->is_tuition === $isTuition || ! $this->hasPaymentRecords($fee)) {
            return;
        }

        throw ValidationException::withMessages([
            'is_tuition' => self::MESSAGE_TYPE_LOCKED,
        ]);
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
        $isTuition = $request->boolean('is_tuition');

        // The conditional "required" rules live here so every missing field is
        // reported at once; resolveFee() re-checks them as the backstop.
        return $request->validate([
            // "School fees" (the class's main fee) or an additional fee.
            'is_tuition' => 'nullable|boolean',
            // Additional fees only: an existing category, or a new one typed here.
            'category_id' => [Rule::requiredIf(! $isTuition && blank($request->input('new_category'))), 'nullable', 'integer'],
            'new_category' => 'nullable|string|max:255',
            // Editing school fees filed under another category: keep it there, or move it into School Fees.
            'school_fees_category' => 'nullable|in:keep,move',
            // Optional for school fees, which are named after their term by default.
            'name' => [Rule::requiredIf(! $isTuition), 'nullable', 'string', 'max:255'],
            'price' => 'nullable|numeric|min:0',
            // School fees only: the period as the admin thinks of it. The internal
            // session/term rows are found or created from these
            // (AcademicPeriodService::termFor). An additional fee is payable in any
            // term, so whatever period it posts is excluded here, never saved.
            'academic_year' => [Rule::excludeIf(! $isTuition), 'required_with:term', 'nullable', 'string', 'max:20'],
            'term' => [
                Rule::excludeIf(! $isTuition),
                Rule::requiredIf($isTuition && blank($request->input('academic_term_id'))),
                'nullable', 'integer', Rule::in(array_keys(AcademicTerm::NAMES)),
            ],
            // Legacy forms posted a term id directly; still honoured for school fees, still owner-checked.
            'academic_term_id' => [Rule::excludeIf(! $isTuition), 'nullable', 'integer'],
            'allows_quantity' => 'nullable|boolean',
            'class_level_ids' => 'nullable|array',
            'class_level_ids.*' => 'integer',
        ], [
            'category_id.required' => 'Choose a category, or type a new one.',
            'name.required' => 'Enter the fee name parents will see, e.g. “Uniform” or “Textbooks”.',
            'academic_year.required_with' => 'Choose the academic year.',
            'term.required' => 'Choose the term these school fees are for.',
            'term.in' => 'Choose First, Second or Third Term.',
        ]);
    }

    /**
     * Turn the form into the fee's category, term and name. Must run inside the
     * saving transaction: it may create the academic year (and, for an additional
     * fee, a new category), and a later failure must roll those back too.
     *
     * $existing is the fee being edited, or null when creating one.
     *
     * @return array{0: Category, 1: AcademicTerm|null, 2: string}
     */
    private function resolveFee(School $school, array $data, Category $schoolFees, AcademicPeriodService $periods, ?Subcategory $existing = null): array
    {
        $isTuition = (bool) ($data['is_tuition'] ?? false);
        // Only school fees are tied to a term. An additional fee is saved payable in
        // any term — a new one, an existing one that had a term, and a school fee
        // switched to additional alike — whatever the request carried.
        $term = $isTuition ? $this->resolveTerm($school, $data, $periods) : null;

        if ($isTuition && $term === null) {
            throw ValidationException::withMessages(['term' => 'Choose the term these school fees are for.']);
        }

        $category = $isTuition
            ? $this->resolveSchoolFeesCategory($data, $schoolFees, $existing)
            : $this->resolveAdditionalCategory($school, $data, $schoolFees);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            if (! $isTuition) {
                throw ValidationException::withMessages(['name' => 'Enter the fee name parents will see, e.g. “Uniform” or “Textbooks”.']);
            }
            $name = $term->name.' School Fees';
        }

        return [$category, $term, $name];
    }

    /**
     * The category of a school-fees (main) fee. A NEW one always goes under the
     * built-in School Fees category. An EXISTING fee keeps the category it already
     * has — fees are never moved into School Fees as a side effect of saving — unless
     * the admin explicitly chose to move it (school_fees_category=move on the edit form).
     */
    private function resolveSchoolFeesCategory(array $data, Category $schoolFees, ?Subcategory $existing): Category
    {
        if ($existing === null || ($data['school_fees_category'] ?? 'keep') === 'move') {
            return $schoolFees;
        }

        return $existing->category ?? $schoolFees;
    }

    /** A school fee's term, from academic year + term or a legacy term id. Never called for an additional fee. */
    private function resolveTerm(School $school, array $data, AcademicPeriodService $periods): ?AcademicTerm
    {
        $year = trim((string) ($data['academic_year'] ?? ''));
        $number = $data['term'] ?? null;

        if ($number === null || $number === '') {
            // No term chosen: payable in any term — unless a legacy form sent a term id.
            return $this->resolveOwnedTerm($school, $data['academic_term_id'] ?? null);
        }

        if ($year === '') {
            throw ValidationException::withMessages(['academic_year' => 'Choose the academic year.']);
        }
        if (! AcademicSession::isValidName($year)) {
            throw ValidationException::withMessages(['academic_year' => 'Enter the academic year as two consecutive years, e.g. 2026/2027.']);
        }

        return $periods->termFor($school, $year, (int) $number);
    }

    /**
     * An additional fee's category: an existing one the school owns, or a new one
     * typed on the form. A typed name that matches an existing category in any
     * spelling ("books", "Book", "BOOKS") reuses it instead of creating a near
     * duplicate, and any spelling of "School Fees" is the built-in category.
     */
    private function resolveAdditionalCategory(School $school, array $data, Category $schoolFees): Category
    {
        $typed = trim((string) ($data['new_category'] ?? ''));
        if ($typed !== '') {
            if (Category::isSchoolFeesName($typed)) {
                return $schoolFees;
            }

            return CategoryController::findByName($school, $typed)
                ?? Category::create(['school_id' => $school->id, 'name' => $typed]);
        }

        if (empty($data['category_id'])) {
            throw ValidationException::withMessages(['category_id' => 'Choose a category, or type a new one.']);
        }

        return $this->resolveOwnedCategory($school, $data['category_id']);
    }

    /** What the create and edit forms need. */
    private function formData(School $school, AcademicPeriodService $periods): array
    {
        $schoolFees = Category::schoolFeesFor($school);

        return [
            'school' => $school,
            'schoolFees' => $schoolFees,
            // Additional fees choose from the school's own categories; School Fees is
            // reached through the "School fees" choice instead.
            'categories' => Category::where('school_id', $school->id)->whereKeyNot($schoolFees->id)->orderBy('name')->get(),
            'years' => $periods->yearOptions($school),
            'defaultYear' => $periods->currentYear($school),
            'defaultTerm' => $school->currentTerm?->number,
            'classLevels' => $school->classLevels()->get(),
        ];
    }

    /**
     * After saving a fee for a term other than the one the payment page opens on,
     * say so: parents are shown the school's current term by default.
     */
    private function currentTermHint(School $school, Subcategory $fee): string
    {
        $current = $school->fresh()->currentTerm;
        if ($fee->academic_term_id === null || $current === null || (int) $current->id === (int) $fee->academic_term_id) {
            return '';
        }

        return ' Your payment page currently opens on '.$current->label.'. Change the current term on this page when the new term starts.';
    }

    /**
     * Tenant-aware listing for a given school.
     */
    public function indexSchool(School $school)
    {
        $subcategories = $this->feesInPageOrder($school);
        $categories = Category::where('school_id', $school->id)->get();

        return view('subcategories.index', [
            'school' => $school,
            'subcategories' => $subcategories,
            'categories' => $categories,
            // For the current-term control re-homed from the retired Sessions page.
            'currentTerm' => $school->currentTerm,
        ]);
    }

    /**
     * The school's fees in the Fees page's presentation order: School Fees first,
     * then by category, then term (fees payable in any term first), then name.
     *
     * @return \Illuminate\Support\Collection<int, Subcategory>
     */
    private function feesInPageOrder(School $school)
    {
        $schoolFees = Category::schoolFeesFor($school);

        return Subcategory::with(['category', 'academicTerm.session', 'classLevels'])
            ->where('school_id', $school->id)
            ->get()
            ->sortBy([
                fn ($a, $b) => (int) ((int) $b->category_id === (int) $schoolFees->id) <=> (int) ((int) $a->category_id === (int) $schoolFees->id),
                fn ($a, $b) => strcasecmp($a->category->name ?? '', $b->category->name ?? ''),
                fn ($a, $b) => strcmp($a->academicTerm?->session?->name ?? '', $b->academicTerm?->session?->name ?? ''),
                fn ($a, $b) => ($a->academicTerm?->number ?? 0) <=> ($b->academicTerm?->number ?? 0),
                fn ($a, $b) => strcasecmp($a->name, $b->name),
            ])
            ->values();
    }

    /**
     * CSV of the school's fees, in the Fees page's order, for this school only.
     *
     * The amount is the school's own price as set on the Fees page — never the
     * platform service fee, and never the total a parent is charged online; neither
     * is computed here. Fee and category names are typed by the school, so every
     * cell is neutralised against spreadsheet formula injection (CsvCell).
     */
    public function exportSchool(School $school): StreamedResponse
    {
        $fees = $this->feesInPageOrder($school);
        $filename = sprintf('%s-fees-%s.csv', $school->slug, BusinessTime::display(now())->format('Ymd-His'));

        return response()->streamDownload(function () use ($fees) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel decodes names with diacritics correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Fee', 'Type', 'Category', 'Amount (NGN)', 'Multiple allowed', 'Applies to', 'Academic year', 'Term']);

            foreach ($fees as $fee) {
                fputcsv($out, CsvCell::row([
                    $fee->name,
                    $fee->is_tuition ? 'School fees' : 'Additional fee',
                    $fee->category->name ?? '',
                    $fee->price === null ? '' : number_format((float) $fee->price, 2, '.', ''),
                    $fee->allows_quantity ? 'Yes' : 'No',
                    $fee->classLevels->isEmpty()
                        ? 'All classes'
                        : $fee->classLevels->sortBy([['position', 'asc'], ['id', 'asc']])->pluck('name')->implode(', '),
                    $fee->academicTerm?->session?->name ?? '',
                    $fee->academicTerm?->name ?? 'Any term',
                ]));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Tenant-aware create view.
     */
    public function createSchool(School $school, AcademicPeriodService $periods)
    {
        return view('subcategories.create', $this->formData($school, $periods));
    }

    /**
     * Tenant-aware store for a given school.
     */
    public function storeSchool(Request $request, School $school, RecordsSchoolAudit $audit, AcademicPeriodService $periods)
    {
        $data = $this->validated($request);

        // Outside the transaction: it may settle a concurrent first use by catching
        // a unique violation, which PostgreSQL does not allow mid-transaction.
        $schoolFees = Category::schoolFeesFor($school);
        $classLevelIds = $this->resolveOwnedClassLevelIds($school, $data['class_level_ids'] ?? null);

        // Fee row and audit row commit together (M7): the price is what parents
        // are charged, so a change to it must never be recorded without the change
        // itself, or the change happen without a record. The academic year a fee
        // creates, if any, rolls back with it.
        $fee = DB::transaction(function () use ($school, $schoolFees, $periods, $data, $classLevelIds, $audit, $request) {
            [$category, $term, $name] = $this->resolveFee($school, $data, $schoolFees, $periods);
            $this->assertMainFeeRules($school, (bool) ($data['is_tuition'] ?? false), $term, $classLevelIds);

            $fee = Subcategory::create([
                'category_id' => $category->id,
                'name' => $name,
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

            return $fee;
        });

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Fee created.'.$this->currentTermHint($school, $fee));
    }

    public function editSchool(School $school, Subcategory $subcategory, AcademicPeriodService $periods)
    {
        $this->assertBelongsToSchool($school, $subcategory);

        $subcategory->load(['classLevels', 'academicTerm.session']);

        return view('subcategories.edit', $this->formData($school, $periods) + [
            'subcategory' => $subcategory,
            'typeLocked' => $this->hasPaymentRecords($subcategory),
        ]);
    }

    public function updateSchool(Request $request, School $school, Subcategory $subcategory, RecordsSchoolAudit $audit, AcademicPeriodService $periods)
    {
        $this->assertBelongsToSchool($school, $subcategory);

        $data = $this->validated($request);

        $schoolFees = Category::schoolFeesFor($school);
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

        DB::transaction(function () use ($subcategory, $school, $schoolFees, $periods, $data, $classLevelIds, $before, $audit, $request) {
            // First, so a refused type change creates nothing (no academic year, no category).
            $this->assertTypeUnchangedOnceUsed($subcategory, (bool) ($data['is_tuition'] ?? false));
            [$category, $term, $name] = $this->resolveFee($school, $data, $schoolFees, $periods, $subcategory);
            $this->assertMainFeeRules($school, (bool) ($data['is_tuition'] ?? false), $term, $classLevelIds, $subcategory->id);

            $subcategory->update([
                'category_id' => $category->id,
                'name' => $name,
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

        // Saving an additional fee that had a term (or a school fee switched to
        // additional) lifted the term limit; say so, as the form warned it would.
        $termLifted = $before['academic_term_id'] !== null && $subcategory->academic_term_id === null
            ? ' It is now payable in any term.'
            : '';

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', 'Fee updated.'.$termLifted.$this->currentTermHint($school, $subcategory));
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
            ->with('success', 'Fee deleted.');
    }
}
