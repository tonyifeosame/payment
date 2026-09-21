{{-- Shared by create and edit. $student is null when creating; $levels is the school's
     class ladder (active levels, plus the student's own level when editing). --}}
@php $hasLadder = $levels->isNotEmpty(); @endphp
<div class="grid grid-cols-1 gap-5 md:grid-cols-2">
    <div>
        <label for="full_name" class="field-label">Full name</label>
        <input id="full_name" name="full_name" value="{{ old('full_name', $student?->full_name) }}" class="field-input {{ $errors->has('full_name') ? 'field-input-error' : '' }}" required maxlength="255" autocomplete="off" @if($errors->has('full_name')) aria-invalid="true" aria-describedby="full_name-error" @endif>
        @error('full_name')<p id="full_name-error" class="field-error">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="admission_number" class="field-label">Admission number</label>
        <input id="admission_number" name="admission_number" value="{{ old('admission_number', $student?->admission_number) }}" class="field-input font-mono {{ $errors->has('admission_number') ? 'field-input-error' : '' }}" required maxlength="50" placeholder="e.g. DA/2026/001" autocomplete="off" aria-describedby="admission_number-help{{ $errors->has('admission_number') ? ' admission_number-error' : '' }}" @if($errors->has('admission_number')) aria-invalid="true" @endif>
        <p id="admission_number-help" class="field-help">Unique within your school. Parents enter this on the payment page.</p>
        @error('admission_number')<p id="admission_number-error" class="field-error">{{ $message }}</p>@enderror
    </div>
    <div>
        @if($hasLadder)
            <label for="class_level_id" class="field-label">Class</label>
            <select id="class_level_id" name="class_level_id" class="field-input {{ $errors->has('class_level_id') ? 'field-input-error' : '' }}" required aria-describedby="class-help{{ $errors->has('class_level_id') ? ' class_level_id-error' : '' }}" @if($errors->has('class_level_id')) aria-invalid="true" @endif>
                <option value="">Choose a class</option>
                @foreach($levels as $level)
                    <option value="{{ $level->id }}" @selected((string) old('class_level_id', $student?->class_level_id) === (string) $level->id)>{{ $level->name }}@if(! $level->is_active) (inactive)@endif</option>
                @endforeach
            </select>
            <p id="class-help" class="field-help">
                @if($student && ! $student->class_level_id && $student->class_name)
                    Recorded as “{{ $student->class_name }}” before your class ladder existed — pick the matching class.
                @else
                    From your class ladder. <a href="{{ route('school.students.classes.index', ['school' => $school->slug]) }}" class="font-medium text-brand-violet underline underline-offset-2">Manage classes</a>
                @endif
            </p>
            @error('class_level_id')<p id="class_level_id-error" class="field-error">{{ $message }}</p>@enderror
        @else
            <label for="class_name" class="field-label">Class</label>
            <input id="class_name" name="class_name" value="{{ old('class_name', $student?->class_name) }}" class="field-input {{ $errors->has('class_name') ? 'field-input-error' : '' }}" required maxlength="100" placeholder="e.g. JSS 1 or Primary 3" aria-describedby="class-help{{ $errors->has('class_name') ? ' class_name-error' : '' }}" @if($errors->has('class_name')) aria-invalid="true" @endif>
            <p id="class-help" class="field-help">You have not set up a class ladder yet, so the class is free text. <a href="{{ route('school.students.classes.index', ['school' => $school->slug]) }}" class="font-medium text-brand-violet underline underline-offset-2">Set up classes</a> to pick from a list and promote students later.</p>
            @error('class_name')<p id="class_name-error" class="field-error">{{ $message }}</p>@enderror
        @endif
    </div>
    <div>
        <label for="academic_session_id" class="field-label">Academic session <span class="font-normal text-brand-slate">(optional)</span></label>
        <select id="academic_session_id" name="academic_session_id" class="field-input {{ $errors->has('academic_session_id') ? 'field-input-error' : '' }}" @if($errors->has('academic_session_id')) aria-invalid="true" aria-describedby="academic_session_id-error" @endif>
            <option value="">Not set</option>
            @foreach($sessions as $s)
                <option value="{{ $s->id }}" @selected((string) old('academic_session_id', $student?->academic_session_id) === (string) $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
        @error('academic_session_id')<p id="academic_session_id-error" class="field-error">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="status" class="field-label">Status</label>
        <select id="status" name="status" class="field-input {{ $errors->has('status') ? 'field-input-error' : '' }}" aria-describedby="status-help">
            @foreach(\App\Models\Student::STATUS_LABELS as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $student?->status ?? \App\Models\Student::STATUS_ACTIVE) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p id="status-help" class="field-help">Only active students appear in the roster by default and are included in promotions. Records are never deleted.</p>
        @error('status')<p class="field-error">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="guardian_name" class="field-label">Parent / guardian name <span class="font-normal text-brand-slate">(optional)</span></label>
        <input id="guardian_name" name="guardian_name" value="{{ old('guardian_name', $student?->guardian_name) }}" class="field-input" maxlength="255" autocomplete="off">
    </div>
    <div>
        <label for="guardian_phone" class="field-label">Guardian phone <span class="font-normal text-brand-slate">(optional)</span></label>
        <input id="guardian_phone" name="guardian_phone" type="tel" value="{{ old('guardian_phone', $student?->guardian_phone) }}" class="field-input" maxlength="30" autocomplete="off">
    </div>
    <div>
        <label for="guardian_email" class="field-label">Guardian email <span class="font-normal text-brand-slate">(optional)</span></label>
        <input id="guardian_email" name="guardian_email" type="email" value="{{ old('guardian_email', $student?->guardian_email) }}" class="field-input {{ $errors->has('guardian_email') ? 'field-input-error' : '' }}" maxlength="255" autocomplete="off" @if($errors->has('guardian_email')) aria-invalid="true" aria-describedby="guardian_email-error" @endif>
        @error('guardian_email')<p id="guardian_email-error" class="field-error">{{ $message }}</p>@enderror
    </div>
</div>
