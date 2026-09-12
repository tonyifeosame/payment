{{-- Shared by create and edit. $subcategory is null when creating. --}}
<div class="space-y-5">
    <div>
        <label for="category_id" class="label">Category</label>
        <select name="category_id" id="category_id" class="input" required>
            <option value="">Select category</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}" @selected((string) old('category_id', $subcategory?->category_id) === (string) $cat->id)>{{ $cat->name }}</option>
            @endforeach
        </select>
        @error('category_id')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="name" class="label">Fee type name</label>
        <input type="text" name="name" id="name" value="{{ old('name', $subcategory?->name) }}" class="input" required maxlength="255" placeholder="e.g. JSS 1 Tuition">
        @error('name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="price" class="label">Price (₦)</label>
        <input type="number" step="0.01" min="0" name="price" id="price" value="{{ old('price', $subcategory?->price) }}" class="input" placeholder="50000">
        @error('price')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="academic_term_id" class="label">Term</label>
        <select name="academic_term_id" id="academic_term_id" class="input">
            <option value="">General — payable in any term (uniform, books…)</option>
            @foreach($terms as $term)
                <option value="{{ $term->id }}" @selected((string) old('academic_term_id', $subcategory?->academic_term_id) === (string) $term->id)>{{ $term->name }}, {{ $term->session->name }}</option>
            @endforeach
        </select>
        <p class="text-xs text-slate-500 mt-1">A term fee can only be paid for that term. @if($terms->isEmpty())<a class="underline" href="{{ route('school.sessions.index', ['school' => $school->slug]) }}">Create a session</a> to add term fees.@endif</p>
        @error('academic_term_id')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
</div>
