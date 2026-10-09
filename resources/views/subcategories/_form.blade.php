{{-- Shared by create and edit. $subcategory is null when creating. The admin names an academic
     year and a term; the server finds or creates the internal session/term rows behind them and
     re-resolves every category and class id within this school — the browser is never
     authoritative. "School fees" always files the fee under the built-in School Fees category. --}}
@php
    $old = session()->hasOldInput();
    // New fees default to School fees, the main fee most schools set up first.
    $isTuition = $old ? (bool) old('is_tuition') : (bool) ($subcategory?->is_tuition ?? true);
    // A fee with payment records keeps its type (SubcategoryController::assertTypeUnchangedOnceUsed):
    // only its own type can be chosen, whatever a failed submit carried.
    $typeLocked = $subcategory && ($typeLocked ?? false);
    if ($typeLocked) {
        $isTuition = (bool) $subcategory->is_tuition;
    }
    $inSchoolFees = $subcategory && (int) $subcategory->category_id === (int) $schoolFees->id;
    $selectedCategory = (string) old('category_id', $subcategory && ! $inSchoolFees ? $subcategory->category_id : '');
    // An additional fee already filed under School Fees (from before it was built in) keeps that choice.
    $offerSchoolFeesCategory = $inSchoolFees && ! $subcategory->is_tuition;
    // An existing fee outside School Fees keeps its category as school fees unless the admin moves it.
    $currentCategoryName = $subcategory && ! $inSchoolFees ? $subcategory->category?->name : null;
    $moveToSchoolFees = old('school_fees_category', 'keep') === 'move';
    if ($offerSchoolFeesCategory && ! $old) {
        $selectedCategory = (string) $schoolFees->id;
    }
    $selectedYear = (string) old('academic_year', $subcategory?->academicTerm?->session?->name ?? $defaultYear);
    // A fee without a term (an additional fee) starts on the current term should the
    // admin switch it to school fees, which always have one.
    $selectedTerm = (string) old('term', $subcategory?->academicTerm?->number ?? $defaultTerm ?? 1);
    // The term a saved fee is limited to, e.g. "First Term, 2026/2027"; saved as an
    // additional fee it becomes payable in any term (SubcategoryController::resolveFee).
    $termLimit = $subcategory?->academicTerm?->label;
    if ($selectedYear !== '' && ! in_array($selectedYear, $years, true)) {
        $years[] = $selectedYear;
        rsort($years);
    }
    // An unticked checkbox is absent from old input, so after a failed submit its
    // absence means "unticked", not "fall back to the saved value".
    $allowsQuantity = $old ? (bool) old('allows_quantity') : (bool) ($subcategory?->allows_quantity ?? false);
    $assignedLevelIds = array_map('strval', $old
        ? (array) old('class_level_ids', [])
        : ($subcategory?->classLevels->pluck('id')->all() ?? []));
    $invalid = fn (string $field) => $errors->has($field) ? 'aria-invalid="true"' : '';
@endphp
<div class="space-y-5" id="feeForm">
    {{-- 1. What kind of fee --}}
    <fieldset aria-describedby="is_tuition-help{{ $typeLocked ? ' is_tuition-locked' : '' }}{{ $errors->has('is_tuition') ? ' is_tuition-error' : '' }}">
        <legend class="field-label">Type of fee</legend>
        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="flex min-h-[64px] cursor-pointer items-start gap-3 rounded-2xl border border-brand-ash/60 p-3 has-[:checked]:border-brand-violet has-[:checked]:bg-brand-violet/5">
                <input type="radio" name="is_tuition" value="1" class="mt-0.5 h-5 w-5 border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" data-fee-kind @checked($isTuition) @disabled($typeLocked && ! $isTuition)>
                <span class="min-w-0">
                    <span class="block font-semibold">School fees</span>
                    <span class="block text-xs text-brand-slate">The main fee for a class and term</span>
                </span>
            </label>
            <label class="flex min-h-[64px] cursor-pointer items-start gap-3 rounded-2xl border border-brand-ash/60 p-3 has-[:checked]:border-brand-violet has-[:checked]:bg-brand-violet/5">
                <input type="radio" name="is_tuition" value="0" class="mt-0.5 h-5 w-5 border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" data-fee-kind @checked(! $isTuition) @disabled($typeLocked && $isTuition)>
                <span class="min-w-0">
                    <span class="block font-semibold">Additional fee</span>
                    <span class="block text-xs text-brand-slate">Uniforms, books, transport, exams …</span>
                </span>
            </label>
        </div>
        @if($typeLocked)
            <p id="is_tuition-locked" class="mt-3 rounded-2xl bg-brand-fog px-4 py-3 text-sm text-brand-slate"><span class="font-semibold text-brand-obsidian">The type of this fee can’t be changed</span> because it already has payment records. To charge it as {{ $isTuition ? 'an additional fee' : 'school fees' }}, create a new fee instead. You can still change its other details.</p>
        @endif
        <p id="is_tuition-help" class="field-help">New school fees are filed under <span class="font-semibold">School Fees</span> automatically, can be paid once per student each term, and are picked for the parent on the payment page. Additional fees stay payable on their own.</p>
        @error('is_tuition')<p id="is_tuition-error" class="field-error">{{ $message }}</p>@enderror
    </fieldset>

    @if($currentCategoryName)
        {{-- Editing a fee that is not in School Fees: as school fees it stays where it is unless moved. --}}
        <fieldset data-tuition-only aria-describedby="school_fees_category-help">
            <legend class="field-label">Category</legend>
            <label class="flex min-h-[48px] cursor-pointer items-center gap-3 text-sm">
                <input type="radio" name="school_fees_category" value="keep" class="h-5 w-5 border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" @checked(! $moveToSchoolFees)>
                <span>Keep it in <span class="font-semibold">{{ $currentCategoryName }}</span></span>
            </label>
            <label class="flex min-h-[48px] cursor-pointer items-center gap-3 text-sm">
                <input type="radio" name="school_fees_category" value="move" class="h-5 w-5 border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" @checked($moveToSchoolFees)>
                <span>Move it to <span class="font-semibold">{{ $schoolFees->name }}</span></span>
            </label>
            <p id="school_fees_category-help" class="field-help">New school fees go under School Fees. This fee was created before that and stays where it is unless you move it. Past payments are not affected either way.</p>
        </fieldset>
    @endif

    {{-- 2. Category: additional fees only --}}
    <div data-additional-only class="space-y-4">
        <div>
            <label for="category_id" class="field-label">Category</label>
            <select name="category_id" id="category_id" class="field-input {{ $errors->has('category_id') ? 'field-input-error' : '' }}" aria-describedby="category-help{{ $errors->has('category_id') ? ' category_id-error' : '' }}" {!! $invalid('category_id') !!}>
                <option value="">{{ $categories->isEmpty() && ! $offerSchoolFeesCategory ? 'No categories yet — type one below' : 'Choose a category' }}</option>
                @if($offerSchoolFeesCategory)
                    <option value="{{ $schoolFees->id }}" @selected($selectedCategory === (string) $schoolFees->id)>{{ $schoolFees->name }}</option>
                @endif
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" @selected($selectedCategory === (string) $cat->id)>{{ $cat->name }}</option>
                @endforeach
            </select>
            <p id="category-help" class="field-help">The group this fee appears under on the payment page.</p>
            @error('category_id')<p id="category_id-error" class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="new_category" class="field-label">Or add a new category <span class="font-normal text-brand-slate">(optional)</span></label>
            <input type="text" name="new_category" id="new_category" value="{{ old('new_category') }}" class="field-input {{ $errors->has('new_category') ? 'field-input-error' : '' }}" maxlength="255" placeholder="e.g. Uniforms" autocomplete="off" aria-describedby="new_category-help{{ $errors->has('new_category') ? ' new_category-error' : '' }}" {!! $invalid('new_category') !!}>
            <p id="new_category-help" class="field-help">If a category with this name already exists, in any spelling, the fee is added to it.</p>
            @error('new_category')<p id="new_category-error" class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- 3. When: school fees only. An additional fee is payable in any term; the server
         ignores any year or term posted with one, and the script disables these fields
         so a hidden value is never submitted. --}}
    <div data-tuition-only>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="academic_year" class="field-label">Academic year</label>
                <select name="academic_year" id="academic_year" data-tuition-field class="field-input {{ $errors->has('academic_year') ? 'field-input-error' : '' }}" @if($errors->has('academic_year')) aria-invalid="true" aria-describedby="academic_year-error" @endif>
                    @foreach($years as $year)
                        <option value="{{ $year }}" @selected($selectedYear === $year)>{{ $year }}</option>
                    @endforeach
                </select>
                @error('academic_year')<p id="academic_year-error" class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="term" class="field-label">Term</label>
                <select name="term" id="term" data-tuition-field class="field-input {{ $errors->has('term') ? 'field-input-error' : '' }}" aria-describedby="term-help{{ $errors->has('term') ? ' term-error' : '' }}" {!! $invalid('term') !!}>
                    @foreach(\App\Models\AcademicTerm::NAMES as $number => $termName)
                        <option value="{{ $number }}" @selected($selectedTerm === (string) $number)>{{ $termName }}</option>
                    @endforeach
                </select>
                @error('term')<p id="term-error" class="field-error">{{ $message }}</p>@enderror
            </div>
        </div>
        <p id="term-help" class="field-help">School fees can only be paid for this term. Additional fees, such as uniforms or books, are payable in any term.</p>
    </div>

    @if($termLimit)
        {{-- A saved fee with a term: as an additional fee it is saved payable in any term.
             Shown while "Additional fee" is chosen — at once for an additional fee from
             before this rule, and on switching for a school fee. --}}
        <div data-additional-only role="status" class="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">Saving will remove this fee’s term</p>
            <p class="mt-1">It is currently limited to {{ $termLimit }}. Additional fees are payable in any term, so once you save it parents can pay it in any term. Payments already made and their receipts are not changed, and the change is recorded in the audit log.</p>
        </div>
    @endif

    {{-- 4. Name --}}
    <div>
        <label for="name" class="field-label">Fee name <span class="font-normal text-brand-slate" data-tuition-only>(optional)</span></label>
        <input type="text" name="name" id="name" value="{{ old('name', $subcategory?->name) }}" class="field-input {{ $errors->has('name') ? 'field-input-error' : '' }}" maxlength="255" placeholder="e.g. First Term School Fees, Uniform" autocomplete="off" aria-describedby="name-help{{ $errors->has('name') ? ' name-error' : '' }}" {!! $invalid('name') !!}>
        <p id="name-help" class="field-help">What parents see. Leave it blank for school fees and it is named after the term, e.g. “First Term School Fees”.</p>
        @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
    </div>

    {{-- 5. Amount --}}
    <div>
        <label for="price" class="field-label">Amount</label>
        <div class="relative mt-2">
            <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center font-display text-base font-bold text-brand-slate" aria-hidden="true">₦</span>
            <input type="number" step="0.01" min="0" inputmode="decimal" name="price" id="price" value="{{ old('price', $subcategory?->price) }}" class="field-input !mt-0 pl-9 font-display text-lg font-bold tabular-nums {{ $errors->has('price') ? 'field-input-error' : '' }}" placeholder="0.00" aria-describedby="price-help{{ $errors->has('price') ? ' price-error' : '' }}" {!! $invalid('price') !!}>
        </div>
        <p id="price-help" class="field-help">In naira. This is the fee amount due to your school for one unit.</p>
        @error('price')<p id="price-error" class="field-error">{{ $message }}</p>@enderror
    </div>

    {{-- 6. Classes. The server re-resolves every id within this school. --}}
    <fieldset aria-describedby="class_levels-help{{ $errors->has('class_level_ids') ? ' class_level_ids-error' : '' }}">
        <legend class="field-label">Applies to classes</legend>
        @if($classLevels->isEmpty())
            <p id="class_levels-help" class="field-help">
                You have no classes set up yet. School fees are charged per class, so
                <a class="font-medium text-brand-violet underline underline-offset-2" href="{{ route('school.students.classes.index', ['school' => $school->slug]) }}">set up your classes</a> first. An additional fee without classes applies to every student.
            </p>
        @else
            <div class="mt-2 grid grid-cols-2 gap-x-4">
                @foreach($classLevels as $level)
                    <label class="flex min-h-[48px] cursor-pointer items-center gap-3 text-sm">
                        <input type="checkbox" name="class_level_ids[]" value="{{ $level->id }}" class="h-5 w-5 rounded border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" @checked(in_array((string) $level->id, $assignedLevelIds, true))>
                        <span class="font-semibold">{{ $level->name }}@unless($level->is_active) <span class="font-normal text-brand-slate">(inactive)</span>@endunless</span>
                    </label>
                @endforeach
            </div>
            <p id="class_levels-help" class="field-help">Only students in a ticked class can pay this fee. School fees need at least one class, and a class has one school fee per term. Leave every class unticked for an additional fee any student can pay.</p>
        @endif
        @error('class_level_ids')<p id="class_level_ids-error" class="field-error">{{ $message }}</p>@enderror
    </fieldset>

    {{-- 7. Quantity: additional fees only --}}
    <div data-additional-only>
        <label class="flex min-h-[48px] cursor-pointer items-center gap-3 text-sm">
            <input type="checkbox" name="allows_quantity" id="allows_quantity" value="1" class="h-5 w-5 rounded border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" aria-describedby="allows_quantity-help{{ $errors->has('allows_quantity') ? ' allows_quantity-error' : '' }}" @checked($allowsQuantity)>
            <span class="font-semibold">Allow multiple units</span>
        </label>
        <p id="allows_quantity-help" class="field-help">Tick this for items a parent may buy more than one of, such as uniforms or books — they choose a quantity and pay the amount above for each. Leave it unticked for fees charged once.</p>
        @error('allows_quantity')<p id="allows_quantity-error" class="field-error">{{ $message }}</p>@enderror
    </div>
</div>

@push('scripts')
<script @nonce>
(function () {
    // Progressive enhancement: hide what does not apply to the chosen type of fee.
    // Without JavaScript every field shows and the server ignores the irrelevant ones.
    var form = document.getElementById('feeForm');
    if (!form) return;
    var radios = form.querySelectorAll('input[data-fee-kind]');
    function refresh(event) {
        var checked = form.querySelector('input[data-fee-kind]:checked');
        var tuition = !checked || checked.value === '1';
        form.querySelectorAll('[data-additional-only]').forEach(function (el) { el.hidden = tuition; });
        form.querySelectorAll('[data-tuition-only]').forEach(function (el) { el.hidden = !tuition; });
        // A disabled field is not submitted, so an additional fee never posts the year
        // and term it cannot have. Switching back re-enables them with the values they
        // held — the fee's saved term, or the school's current one for a new fee.
        form.querySelectorAll('[data-tuition-field]').forEach(function (el) { el.disabled = !tuition; });
        if (tuition && event) { var qty = document.getElementById('allows_quantity'); if (qty) qty.checked = false; }
    }
    radios.forEach(function (r) { r.addEventListener('change', refresh); });
    refresh();
})();
</script>
@endpush
