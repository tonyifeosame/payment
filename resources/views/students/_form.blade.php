{{-- Shared by create and edit. $student is null when creating. --}}
<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <div>
        <label for="full_name" class="label">Full name</label>
        <input id="full_name" name="full_name" value="{{ old('full_name', $student?->full_name) }}" class="input" required maxlength="255">
        @error('full_name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="admission_number" class="label">Admission number</label>
        <input id="admission_number" name="admission_number" value="{{ old('admission_number', $student?->admission_number) }}" class="input font-mono" required maxlength="50" placeholder="e.g. DA/2026/001">
        <p class="text-xs text-slate-500 mt-1">Unique within your school. Parents will enter this on the payment page.</p>
        @error('admission_number')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="class_name" class="label">Class</label>
        <input id="class_name" name="class_name" value="{{ old('class_name', $student?->class_name) }}" class="input" required maxlength="100" placeholder="e.g. JSS 1 or Primary 3">
        @error('class_name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="academic_session_id" class="label">Academic session</label>
        <select id="academic_session_id" name="academic_session_id" class="input">
            <option value="">Not set</option>
            @foreach($sessions as $s)
                <option value="{{ $s->id }}" @selected((string) old('academic_session_id', $student?->academic_session_id) === (string) $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
        @error('academic_session_id')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="guardian_name" class="label">Parent / guardian name <span class="text-slate-400 font-normal">(optional)</span></label>
        <input id="guardian_name" name="guardian_name" value="{{ old('guardian_name', $student?->guardian_name) }}" class="input" maxlength="255">
    </div>
    <div>
        <label for="guardian_phone" class="label">Guardian phone <span class="text-slate-400 font-normal">(optional)</span></label>
        <input id="guardian_phone" name="guardian_phone" value="{{ old('guardian_phone', $student?->guardian_phone) }}" class="input" maxlength="30">
    </div>
    <div class="md:col-span-2">
        <label for="guardian_email" class="label">Guardian email <span class="text-slate-400 font-normal">(optional)</span></label>
        <input id="guardian_email" name="guardian_email" type="email" value="{{ old('guardian_email', $student?->guardian_email) }}" class="input" maxlength="255">
        @error('guardian_email')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
</div>
