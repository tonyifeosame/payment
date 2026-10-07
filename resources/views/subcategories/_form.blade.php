{{-- Shared by create and edit. $subcategory is null when creating. Field names, validation
     and the single term select (session shown alongside each term) are unchanged; the
     browser value is never authoritative — the server re-resolves category and term. --}}
@php
    $termsBySession = $terms->groupBy(fn ($term) => $term->session->name);
    $selectedTerm = (string) old('academic_term_id', $subcategory?->academic_term_id);
    // An unticked checkbox is absent from old input, so after a failed submit its
    // absence means "unticked", not "fall back to the saved value".
    $allowsQuantity = session()->hasOldInput() ? (bool) old('allows_quantity') : (bool) ($subcategory?->allows_quantity ?? false);
    $isTuition = session()->hasOldInput() ? (bool) old('is_tuition') : (bool) ($subcategory?->is_tuition ?? false);
    $assignedLevelIds = array_map('strval', session()->hasOldInput()
        ? (array) old('class_level_ids', [])
        : ($subcategory?->classLevels->pluck('id')->all() ?? []));
@endphp
<div class="space-y-5">
    <div>
        <label for="category_id" class="field-label">Category</label>
        <select name="category_id" id="category_id" class="field-input {{ $errors->has('category_id') ? 'field-input-error' : '' }}" required aria-describedby="category-help{{ $errors->has('category_id') ? ' category_id-error' : '' }}" @if($errors->has('category_id')) aria-invalid="true" @endif>
            <option value="">Choose a category</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}" @selected((string) old('category_id', $subcategory?->category_id) === (string) $cat->id)>{{ $cat->name }}</option>
            @endforeach
        </select>
        <p id="category-help" class="field-help">
            @if($categories->isEmpty())
                You have no categories yet — <a class="font-medium text-brand-violet underline underline-offset-2" href="{{ route('school.categories.index', ['school' => $school->slug]) }}">create one</a> before adding fee types.
            @else
                The group this fee appears under on the payment page.
            @endif
        </p>
        @error('category_id')<p id="category_id-error" class="field-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="name" class="field-label">Fee type</label>
        <input type="text" name="name" id="name" value="{{ old('name', $subcategory?->name) }}" class="field-input {{ $errors->has('name') ? 'field-input-error' : '' }}" required maxlength="255" placeholder="e.g. First Term Tuition" autocomplete="off" aria-describedby="name-help{{ $errors->has('name') ? ' name-error' : '' }}" @if($errors->has('name')) aria-invalid="true" @endif>
        <p id="name-help" class="field-help">What parents pick, e.g. “First Term Tuition” or “Shirt”.</p>
        @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="price" class="field-label">Amount</label>
        <div class="relative mt-2">
            <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center font-display text-base font-bold text-brand-slate" aria-hidden="true">₦</span>
            <input type="number" step="0.01" min="0" inputmode="decimal" name="price" id="price" value="{{ old('price', $subcategory?->price) }}" class="field-input !mt-0 pl-9 font-display text-lg font-bold tabular-nums {{ $errors->has('price') ? 'field-input-error' : '' }}" placeholder="0.00" aria-describedby="price-help{{ $errors->has('price') ? ' price-error' : '' }}" @if($errors->has('price')) aria-invalid="true" @endif>
        </div>
        <p id="price-help" class="field-help">In naira. This is the fee amount due to your school for one unit.</p>
        @error('price')<p id="price-error" class="field-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="flex min-h-[48px] cursor-pointer items-center gap-3 text-sm">
            <input type="checkbox" name="allows_quantity" id="allows_quantity" value="1" class="h-5 w-5 rounded border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" aria-describedby="allows_quantity-help{{ $errors->has('allows_quantity') ? ' allows_quantity-error' : '' }}" @checked($allowsQuantity)>
            <span class="font-semibold">Allow multiple units</span>
        </label>
        <p id="allows_quantity-help" class="field-help">Tick this for items a parent may buy more than one of, such as uniforms or books — they choose a quantity and pay the amount above for each. Leave it unticked for fees charged once, such as tuition.</p>
        @error('allows_quantity')<p id="allows_quantity-error" class="field-error">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="academic_term_id" class="field-label">Academic session and term</label>
        <select name="academic_term_id" id="academic_term_id" class="field-input {{ $errors->has('academic_term_id') ? 'field-input-error' : '' }}" aria-describedby="term-help{{ $errors->has('academic_term_id') ? ' academic_term_id-error' : '' }}" @if($errors->has('academic_term_id')) aria-invalid="true" @endif>
            <option value="" @selected($selectedTerm === '')>General — payable in any term</option>
            @foreach($termsBySession as $sessionName => $sessionTerms)
                <optgroup label="{{ $sessionName }}">
                    @foreach($sessionTerms as $term)
                        <option value="{{ $term->id }}" @selected($selectedTerm === (string) $term->id)>{{ $term->name }}, {{ $sessionName }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <p id="term-help" class="field-help">
            A term fee can only be paid for that term. Use “General” for things like uniforms or books.
            @if($terms->isEmpty())<a class="font-medium text-brand-violet underline underline-offset-2" href="{{ route('school.sessions.index', ['school' => $school->slug]) }}">Create a session</a> to add term fees.@endif
        </p>
        @error('academic_term_id')<p id="academic_term_id-error" class="field-error">{{ $message }}</p>@enderror
    </div>

    {{-- Fee assignment: the class levels this fee applies to. The server re-resolves
         every id within this school; none ticked means every student can pay it. --}}
    <fieldset aria-describedby="class_levels-help{{ $errors->has('class_level_ids') ? ' class_level_ids-error' : '' }}">
        <legend class="field-label">Applies to classes</legend>
        @if($classLevels->isEmpty())
            <p id="class_levels-help" class="field-help">
                You have no classes set up yet, so this fee applies to every student.
                <a class="font-medium text-brand-violet underline underline-offset-2" href="{{ route('school.students.classes.index', ['school' => $school->slug]) }}">Set up classes</a> to assign fees to a class.
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
            <p id="class_levels-help" class="field-help">Only students in a ticked class can pay this fee. Leave every class unticked for a fee any student can pay, such as uniforms or books.</p>
        @endif
        @error('class_level_ids')<p id="class_level_ids-error" class="field-error">{{ $message }}</p>@enderror
    </fieldset>

    <div>
        <label class="flex min-h-[48px] cursor-pointer items-center gap-3 text-sm">
            <input type="checkbox" name="is_tuition" id="is_tuition" value="1" class="h-5 w-5 rounded border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" aria-describedby="is_tuition-help{{ $errors->has('is_tuition') ? ' is_tuition-error' : '' }}" @checked($isTuition)>
            <span class="font-semibold">Main class fee (tuition)</span>
        </label>
        <p id="is_tuition-help" class="field-help">When this is the only main fee for a student's class and term, the payment page selects it for the parent automatically. Other fees stay available as additional payments.</p>
        @error('is_tuition')<p id="is_tuition-error" class="field-error">{{ $message }}</p>@enderror
    </div>
</div>
