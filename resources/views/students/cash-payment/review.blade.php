@extends('layouts.admin')

@section('subnav')
    @include('admin._subnav', ['section' => 'students'])
@endsection

@section('title', 'Confirm cash payment')
@section('eyebrow', 'School · Students')
@section('heading', 'Confirm cash payment')
@section('subheading', 'Check the summary. Nothing has been recorded yet.')

@section('content')
@php
    $money = fn ($v) => '₦'.number_format((float) $v, 2);
    $fee = $quote['fee'];
    $term = $quote['term'];
    $back = route('school.students.cash-payment.create', ['school' => $school->slug, 'student' => $student->id, 'academic_year' => $term->session->name, 'term' => $term->number]);
@endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
    <section class="card p-5 sm:p-6 lg:col-span-2" aria-labelledby="summary-heading">
        <h2 id="summary-heading" class="font-display text-lg font-bold tracking-tight">You are about to record</h2>
        <p class="mt-2 font-display text-3xl font-extrabold tabular-nums tracking-tight">{{ $money($quote['amount']) }} <span class="text-lg font-bold text-brand-slate">in cash</span></p>

        <dl class="mt-5 divide-y divide-brand-fog border-t border-brand-fog text-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Student</dt><dd class="font-semibold">{{ $student->full_name }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Admission no.</dt><dd class="break-all font-mono text-[13px] font-semibold">{{ $student->admission_number }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Class</dt><dd class="font-medium">{{ $quote['class_name'] }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Academic year and term</dt><dd class="font-medium">{{ $term->name }}, {{ $term->session->name }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">School fee</dt><dd class="font-medium">{{ $fee->name }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="font-semibold text-brand-obsidian">Amount</dt><dd class="font-display text-base font-bold tabular-nums">{{ $money($quote['amount']) }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Payment date</dt><dd class="font-medium">{{ $paidOn->format('d M Y') }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Received by</dt><dd class="font-medium">{{ $details['received_by'] }}</dd></div>
            @if(filled($details['receipt_number'] ?? null))
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Cash receipt no.</dt><dd class="break-all font-mono text-[13px] font-medium">{{ $details['receipt_number'] }}</dd></div>
            @endif
            @if(filled($details['notes'] ?? null))
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Notes</dt><dd class="break-words font-medium">{{ $details['notes'] }}</dd></div>
            @endif
        </dl>

        <form method="POST" action="{{ route('school.students.cash-payment.store', ['school' => $school->slug, 'student' => $student->id]) }}" class="mt-6">
            @csrf
            <input type="hidden" name="academic_year" value="{{ $term->session->name }}">
            <input type="hidden" name="term" value="{{ $term->number }}">
            <input type="hidden" name="paid_on" value="{{ $details['paid_on'] }}">
            <input type="hidden" name="received_by" value="{{ $details['received_by'] }}">
            <input type="hidden" name="receipt_number" value="{{ $details['receipt_number'] ?? '' }}">
            <input type="hidden" name="notes" value="{{ $details['notes'] ?? '' }}">
            {{-- What this page showed. The server re-derives every one of these and refuses
                 to record if anything changed; they are compared, never used. --}}
            <input type="hidden" name="expected_fee_id" value="{{ $fee->id }}">
            <input type="hidden" name="expected_amount" value="{{ $quote['amount'] }}">
            <input type="hidden" name="expected_term_id" value="{{ $term->id }}">
            <input type="hidden" name="expected_class_level_id" value="{{ $student->class_level_id }}">

            <label class="flex items-start gap-3 rounded-2xl border border-brand-ash/60 p-4 text-sm">
                <input type="checkbox" name="confirm_received" value="1" required class="mt-0.5 h-5 w-5 shrink-0 rounded border-brand-ash text-brand-obsidian focus:ring-brand-violet">
                <span>I confirm the school has received <span class="font-semibold">{{ $money($quote['amount']) }}</span> in cash, in full, for {{ $student->full_name }}’s {{ $term->name }}, {{ $term->session->name }} school fees.</span>
            </label>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                <a href="{{ $back }}" class="btn-outline">Back to details</a>
                <button type="submit" class="btn-obsidian">Record cash payment</button>
            </div>
        </form>
    </section>

    <aside class="space-y-6">
        <section class="card p-5 sm:p-6" aria-labelledby="what-heading">
            <h2 id="what-heading" class="font-display text-lg font-bold tracking-tight">What happens next</h2>
            <ul class="mt-3 space-y-2 text-sm text-brand-slate">
                <li>The fee is marked paid for this term and shows in the payment history as <span class="font-semibold text-brand-obsidian">Paid with Cash</span>.</li>
                <li>A printable receipt is available. No email is sent.</li>
                <li>No online charge, service fee or payout is involved: FEYRA did not collect this money.</li>
                <li>If it was recorded in error, it can be voided with a reason.</li>
            </ul>
        </section>
        @if($quote['pending'])
            <div class="rounded-2xl border border-brand-violet/20 bg-brand-violet/10 p-4 text-sm text-brand-obsidian" role="status" data-pending-online>
                <p class="font-semibold">An online payment is in progress</p>
                <p class="mt-1">A parent started an online payment for this term on {{ \App\Support\BusinessTime::display($quote['pending']->created_at)?->format('d M Y \a\t H:i') }}. If it completes after you record cash, it will be held for a refund — it will not be counted twice.</p>
            </div>
        @endif
    </aside>
</div>
@endsection
