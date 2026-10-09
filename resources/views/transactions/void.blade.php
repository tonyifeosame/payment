@extends('layouts.admin')

@section('subnav')
    @include('admin._subnav', ['section' => 'payments'])
@endsection

@php
    $t = $transaction;
    $money = fn ($v) => '₦'.number_format((float) $v, 2);
    $paidOn = \App\Support\BusinessTime::display($t->paid_at);
@endphp

@section('title', 'Void cash payment')
@section('eyebrow', 'Payments · Transaction')
@section('heading', 'Void cash payment')
@section('subheading', 'For a cash payment recorded in error. The record is kept and the void is logged.')
@section('actions')
    <a href="{{ route('school.transactions.show', ['school' => $school->slug, 'transaction' => $t->id]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        Back to payment
    </a>
@endsection

@section('content')
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
    <section class="card p-5 sm:p-6 lg:col-span-2" aria-labelledby="void-heading">
        <h2 id="void-heading" class="font-display text-lg font-bold tracking-tight">Cash payment</h2>
        <dl class="mt-3 divide-y divide-brand-fog border-t border-brand-fog text-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Student</dt><dd class="font-semibold">{{ $t->student_name }} <span class="font-mono text-[13px] font-medium text-brand-slate">{{ $t->student_admission_number }}</span></dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">School fee</dt><dd class="font-medium">{{ $t->subcategory_name }} · {{ $t->term_name }}, {{ $t->session_name }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Amount</dt><dd class="font-display text-base font-bold tabular-nums">{{ $money($t->amount) }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Paid on</dt><dd class="font-medium">{{ $paidOn?->format('d M Y') }}</dd></div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Received by</dt><dd class="font-medium">{{ $t->received_by }}</dd></div>
            @if($t->manual_receipt_number)
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3"><dt class="text-brand-slate">Cash receipt no.</dt><dd class="break-all font-mono text-[13px] font-medium">{{ $t->manual_receipt_number }}</dd></div>
            @endif
        </dl>

        <form method="POST" action="{{ route('school.transactions.void', ['school' => $school->slug, 'transaction' => $t->id]) }}" class="mt-6"
              data-confirm="The payment stays on record marked Voided, stops counting as paid, and the school fee for {{ $t->term_name }}, {{ $t->session_name }} becomes payable again. This cannot be undone."
              data-confirm-title="Void this cash payment?"
              data-confirm-label="Void payment"
              data-confirm-tone="danger">
            @csrf
            <label for="reason" class="field-label">Reason for voiding</label>
            <textarea id="reason" name="reason" rows="3" required minlength="5" maxlength="500" class="field-input {{ $errors->has('reason') ? 'field-input-error' : '' }}" @if($errors->has('reason')) aria-invalid="true" @endif>{{ old('reason') }}</textarea>
            <p class="field-help">For example: “Recorded against the wrong student” or “Cash was returned to the parent”.</p>

            <label class="mt-4 flex items-start gap-3 rounded-2xl border border-brand-ash/60 p-4 text-sm">
                <input type="checkbox" name="confirm_void" value="1" required class="mt-0.5 h-5 w-5 shrink-0 rounded border-brand-ash text-brand-obsidian focus:ring-brand-violet">
                <span>I understand this payment will no longer count as paid. The original record and this void are kept.</span>
            </label>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                <a href="{{ route('school.transactions.show', ['school' => $school->slug, 'transaction' => $t->id]) }}" class="btn-outline">Cancel</a>
                <button type="submit" class="btn-danger">Void cash payment</button>
            </div>
        </form>
    </section>

    <aside class="space-y-4">
        <section class="card p-5 sm:p-6" aria-labelledby="effect-heading">
            <h2 id="effect-heading" class="font-display text-lg font-bold tracking-tight">What voiding does</h2>
            <ul class="mt-3 space-y-2 text-sm text-brand-slate">
                <li>The payment is kept, marked <span class="font-semibold text-brand-obsidian">Cash — Voided</span>, with your reason.</li>
                <li>It stops counting as paid, so the school fee for this term can be paid again.</li>
                <li>No money moves: cash payments never involve FEYRA or a payout.</li>
            </ul>
        </section>
        @unless($termIsCurrent)
            <div class="rounded-2xl border border-brand-violet/20 bg-brand-violet/10 p-4 text-sm text-brand-obsidian" role="status" data-term-not-current>
                <p class="font-semibold">{{ $t->term_name }}, {{ $t->session_name }} is not your current term</p>
                <p class="mt-1">After voiding, cash for this term can only be recorded again if you make it the current term on the Fees page.</p>
            </div>
        @endunless
        @if($heldDuplicates->isNotEmpty())
            <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert" data-held-duplicate>
                <p class="font-semibold">An online payment for this term is held for a refund</p>
                <p class="mt-1">A parent also paid this school fee online after the cash was recorded. Voiding the cash does not change that payment: it stays held for review. Contact FEYRA support about it, quoting {{ $heldDuplicates->pluck('reference')->implode(', ') }}.</p>
            </div>
        @endif
    </aside>
</div>
@endsection
