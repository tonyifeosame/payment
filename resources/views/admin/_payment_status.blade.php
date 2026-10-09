{{-- A payment's status as the admin lists show it. A paid row says how it was paid —
     "Paid with Card", "Paid with Bank Transfer", "Paid with Cash" — so cash the school
     recorded is never mistaken for a Paystack payment; a withdrawn cash entry reads
     "Cash — Voided". Usage: @include('admin._payment_status', ['transaction' => $t]) --}}
@php
    $paymentStatusLabel = match ($transaction->status) {
        \App\Models\Transaction::STATUS_SUCCESS => $transaction->methodLabel(),
        \App\Models\Transaction::STATUS_VOIDED => 'Cash — Voided',
        default => null,
    };
@endphp
@include('admin._badge', ['status' => $transaction->status, 'label' => $paymentStatusLabel])
@if($transaction->isManual())
    <span class="mt-1 block text-xs text-brand-slate">Recorded by school</span>
@endif
