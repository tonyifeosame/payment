@extends('layouts.admin')

@section('subnav')
    @include('admin._subnav', ['section' => 'students'])
@endsection

@section('title', 'Record cash payment')
@section('eyebrow', 'School · Students')
@section('heading', 'Record cash payment')
@section('subheading', 'School fees paid in cash at the school. Nothing is recorded until you review and confirm.')
@section('actions')
    <a href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $student->id]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        Back to student
    </a>
@endsection

@section('content')
@php
    $money = fn ($v) => '₦'.number_format((float) $v, 2);
    $fee = $quote['fee'];
    $term = $quote['term'];
    $canRecord = $quote['problem'] === null;
@endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
    <div class="space-y-6 lg:col-span-2">
        {{-- 1. Student (fixed: the profile this was opened from) --}}
        <section class="card p-5 sm:p-6" aria-labelledby="student-heading">
            <h2 id="student-heading" class="font-display text-lg font-bold tracking-tight">Student</h2>
            <dl class="mt-3 grid grid-cols-1 gap-x-6 text-sm sm:grid-cols-3">
                <div class="py-2"><dt class="text-brand-slate">Name</dt><dd class="font-semibold">{{ $student->full_name }}</dd></div>
                <div class="py-2"><dt class="text-brand-slate">Admission no.</dt><dd class="break-all font-mono text-[13px] font-semibold">{{ $student->admission_number }}</dd></div>
                <div class="py-2"><dt class="text-brand-slate">Class</dt><dd class="font-semibold">{{ $student->classLevel?->name ?? $student->class_name ?? '—' }}</dd></div>
            </dl>
        </section>

        {{-- 2. Term → the school fee it resolves to --}}
        <form method="GET" action="{{ route('school.students.cash-payment.create', ['school' => $school->slug, 'student' => $student->id]) }}" class="card p-5 sm:p-6" aria-labelledby="term-heading">
            <h2 id="term-heading" class="font-display text-lg font-bold tracking-tight">Academic year and term</h2>
            <p class="mt-1 text-sm text-brand-slate">
                @if($currentTerm)
                    Cash can only be recorded for your school’s current term, <span class="font-semibold text-brand-obsidian">{{ $currentTerm->label }}</span>.
                @else
                    Cash can only be recorded for your school’s current term. Set it on the Fees page first.
                @endif
            </p>
            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="sm:flex-1">
                    <label for="academic_year" class="field-label">Academic year</label>
                    <select id="academic_year" name="academic_year" class="field-input">
                        @foreach($years as $session)
                            @php $open = ! $currentTerm || (int) $currentTerm->academic_session_id === (int) $session->id; @endphp
                            <option value="{{ $session->name }}" @selected($year === $session->name) @disabled(! $open)>{{ $session->name }}@unless($open) (not open for payment)@endunless</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:flex-1">
                    <label for="term" class="field-label">Term</label>
                    <select id="term" name="term" class="field-input">
                        @foreach(\App\Models\AcademicTerm::NAMES as $termNumber => $termName)
                            @php $open = ! $currentTerm || (int) $currentTerm->number === (int) $termNumber; @endphp
                            <option value="{{ $termNumber }}" @selected($number === (string) $termNumber) @disabled(! $open)>{{ $termName }}@unless($open) (not open for payment)@endunless</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn-outline">Find school fee</button>
            </div>
        </form>

        @if($canRecord)
            {{-- 3. Payment details --}}
            <form method="POST" action="{{ route('school.students.cash-payment.review', ['school' => $school->slug, 'student' => $student->id]) }}" class="card p-5 sm:p-6" aria-labelledby="details-heading">
                @csrf
                <input type="hidden" name="academic_year" value="{{ $term->session->name }}">
                <input type="hidden" name="term" value="{{ $term->number }}">
                <h2 id="details-heading" class="font-display text-lg font-bold tracking-tight">Payment details</h2>
                <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <label for="paid_on" class="field-label">Payment date</label>
                        <input id="paid_on" name="paid_on" type="date" value="{{ old('paid_on', $today) }}" max="{{ $today }}" required class="field-input {{ $errors->has('paid_on') ? 'field-input-error' : '' }}" aria-describedby="paid_on-help" @if($errors->has('paid_on')) aria-invalid="true" @endif>
                        <p id="paid_on-help" class="field-help">The day the cash was received. Not a future date.</p>
                    </div>
                    <div>
                        <label for="received_by" class="field-label">Received by</label>
                        <input id="received_by" name="received_by" value="{{ old('received_by') }}" required maxlength="100" autocomplete="off" class="field-input {{ $errors->has('received_by') ? 'field-input-error' : '' }}" aria-describedby="received_by-help" @if($errors->has('received_by')) aria-invalid="true" @endif>
                        <p id="received_by-help" class="field-help">Name of the staff member who took the cash.</p>
                    </div>
                    <div>
                        <label for="receipt_number" class="field-label">Cash receipt no. <span class="font-normal text-brand-slate">(optional)</span></label>
                        <input id="receipt_number" name="receipt_number" value="{{ old('receipt_number') }}" maxlength="100" autocomplete="off" class="field-input font-mono {{ $errors->has('receipt_number') ? 'field-input-error' : '' }}" aria-describedby="receipt_number-help" @if($errors->has('receipt_number')) aria-invalid="true" @endif>
                        <p id="receipt_number-help" class="field-help">The number on the school’s own paper receipt, if one was issued.</p>
                    </div>
                    <div>
                        <label for="notes" class="field-label">Notes <span class="font-normal text-brand-slate">(optional)</span></label>
                        <input id="notes" name="notes" value="{{ old('notes') }}" maxlength="500" autocomplete="off" class="field-input">
                    </div>
                </div>
                <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                    <a href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $student->id]) }}" class="btn-outline">Cancel</a>
                    <button type="submit" class="btn-obsidian">Review payment</button>
                </div>
            </form>
        @endif
    </div>

    {{-- The school fee (or why there is none) --}}
    <aside class="card p-5 sm:p-6" aria-labelledby="fee-heading">
        <h2 id="fee-heading" class="font-display text-lg font-bold tracking-tight">School fee due</h2>
        @if($fee)
            <dl class="mt-3 divide-y divide-brand-fog text-sm">
                <div class="flex items-baseline justify-between gap-4 py-2.5"><dt class="text-brand-slate">Fee</dt><dd class="text-right font-semibold">{{ $fee->name }}</dd></div>
                <div class="flex items-baseline justify-between gap-4 py-2.5"><dt class="text-brand-slate">Class</dt><dd class="text-right font-medium">{{ $quote['class_name'] }}</dd></div>
                <div class="flex items-baseline justify-between gap-4 py-2.5"><dt class="text-brand-slate">Academic year</dt><dd class="text-right font-medium">{{ $term?->session?->name }}</dd></div>
                <div class="flex items-baseline justify-between gap-4 py-2.5"><dt class="text-brand-slate">Term</dt><dd class="text-right font-medium">{{ $term?->name }}</dd></div>
                @if($quote['amount'] !== null)
                    <div class="flex items-baseline justify-between gap-4 py-2.5"><dt class="font-semibold text-brand-obsidian">Amount</dt><dd class="text-right font-display text-xl font-extrabold tabular-nums">{{ $money($quote['amount']) }}</dd></div>
                @endif
            </dl>
            <p class="mt-3 text-sm text-brand-slate">The amount is the school fee set on your Fees page. Only full payment can be recorded.</p>
        @endif

        @if($quote['problem'])
            <div class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert" data-cash-problem>
                <p class="font-semibold">This payment can’t be recorded</p>
                <p class="mt-1">{{ $quote['problem'] }}</p>
            </div>
        @endif

        @if($quote['pending'])
            <div class="mt-4 rounded-2xl border border-brand-violet/20 bg-brand-violet/10 p-4 text-sm text-brand-obsidian" role="status" data-pending-online>
                <p class="font-semibold">An online payment is in progress</p>
                <p class="mt-1">A parent started an online payment for this term on {{ \App\Support\BusinessTime::display($quote['pending']->created_at)?->format('d M Y \a\t H:i') }}. If it completes after you record cash, it will be held for a refund — it will not be counted twice.</p>
            </div>
        @endif
    </aside>
</div>
@endsection
