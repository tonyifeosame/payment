@extends('layouts.admin')

@php
    $t = $transaction;
    $isPaid = $t->status === \App\Models\Transaction::STATUS_SUCCESS;
    $when = $t->paid_at ?? $t->created_at;
    $money = fn ($v) => '₦'.number_format((float) $v, 2);
    [$payoutGroup, $payoutHeadline, $payoutNote] = [$payoutState['group'], $payoutState['headline'], $payoutState['note']];
@endphp

@section('title', 'Transaction '.$t->reference)
@section('eyebrow', 'Payments · Transaction')
@section('heading', $t->subcategory_name ?? 'Payment')
@section('subheading', ($t->hasStudent() ? 'For '.$t->student_name.' · ' : '').'Ref '.$t->reference)
@section('actions')
    <a href="{{ route('school.transactions.index', ['school' => $school->slug]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        All transactions
    </a>
    @if($isPaid)
        <a href="{{ $downloadUrl }}" class="btn-outline">Download PDF</a>
        <a href="{{ route('payment.receipt', $t->id) }}" class="btn-obsidian">View receipt</a>
    @endif
@endsection

@section('content')
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

    {{-- Payment --}}
    <section class="card p-5 sm:p-6 lg:col-span-2" aria-labelledby="payment-heading">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div>
                <h2 id="payment-heading" class="font-display text-lg font-bold tracking-tight">Payment</h2>
                <p class="mt-1 text-sm text-brand-slate">
                    @if($isPaid && $t->paid_at)
                        Confirmed <time datetime="{{ $t->paid_at->toIso8601String() }}">{{ $t->paid_at->format('d M Y \a\t H:i') }}</time>
                    @elseif($isPaid)
                        Confirmed · started <time datetime="{{ $t->created_at?->toIso8601String() }}">{{ $t->created_at?->format('d M Y \a\t H:i') }}</time>
                    @else
                        Started <time datetime="{{ $t->created_at?->toIso8601String() }}">{{ $t->created_at?->format('d M Y \a\t H:i') }}</time>
                    @endif
                </p>
            </div>
            @include('admin._badge', ['status' => $t->status])
        </div>

        {{-- Only the school's own money is shown: the platform's service charge is
             never presented to the school admin. --}}
        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-2xl bg-brand-fog p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate">{{ $isPaid ? 'School amount' : 'Fee amount' }}</p>
                <p class="mt-1 font-display text-2xl font-extrabold tabular-nums tracking-tight">{{ $money($breakdown['fee_subtotal']) }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ash/60 p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate">Quantity</p>
                <p class="mt-1 font-display text-2xl font-extrabold tabular-nums tracking-tight">{{ $breakdown['quantity'] }} <span class="text-base font-bold text-brand-slate">× {{ $money($breakdown['unit_price']) }}</span></p>
            </div>
        </div>

        <dl class="mt-5 divide-y divide-brand-fog border-t border-brand-fog text-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                <dt class="text-brand-slate">Reference</dt>
                <dd class="break-all font-mono text-[13px] font-medium">{{ $t->reference }}</dd>
            </div>
            @if($t->paystack_reference)
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                    <dt class="text-brand-slate">Paystack reference</dt>
                    <dd class="break-all font-mono text-[13px] font-medium">{{ $t->paystack_reference }}</dd>
                </div>
            @endif
            @if($t->payment_method)
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                    <dt class="text-brand-slate">Payment method</dt>
                    <dd class="font-medium">{{ ucfirst(str_replace('_', ' ', $t->payment_method)) }}</dd>
                </div>
            @endif
            @unless($isPaid)
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                    <dt class="text-brand-slate">Receipt</dt>
                    <dd class="text-brand-slate">Issued once the payment is confirmed</dd>
                </div>
            @endunless
        </dl>
    </section>

    {{-- Payout --}}
    <section class="card p-5 sm:p-6 lg:col-start-3 lg:row-start-1" aria-labelledby="payout-heading">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <h2 id="payout-heading" class="font-display text-lg font-bold tracking-tight">Payout</h2>
            @if($payout)
                @include('admin._badge', ['status' => $payout->status, 'label' => $payoutLabels[$payout->status] ?? ucfirst($payout->status)])
            @endif
        </div>
        <p class="mt-3 font-display text-xl font-bold tracking-tight {{ $payoutGroup === 'attention' ? 'text-red-700' : ($payoutGroup === 'paid' ? 'text-green-800' : '') }}">{{ $payoutHeadline }}</p>
        <p class="mt-1 text-sm text-brand-slate">{{ $payoutNote }}</p>

        @if($isPaid)
            <dl class="mt-5 divide-y divide-brand-fog border-t border-brand-fog text-sm">
                <div class="flex items-baseline justify-between gap-4 py-3">
                    <dt class="text-brand-slate">School amount</dt>
                    <dd class="font-medium tabular-nums">{{ $money($breakdown['fee_subtotal']) }}</dd>
                </div>
                @if($payout)
                    <div class="flex items-baseline justify-between gap-4 py-3">
                        <dt class="font-semibold text-brand-obsidian">Payout</dt>
                        <dd class="font-display text-base font-bold tabular-nums">
                            @if($payout->status === \App\Models\Payout::NEEDS_REVIEW)
                                <span class="text-brand-slate">Under review</span>
                            @else
                                {{ $money($payout->amount) }}
                            @endif
                        </dd>
                    </div>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                        <dt class="text-brand-slate">Payout reference</dt>
                        <dd class="break-all font-mono text-[13px] font-medium">{{ $payout->reference }}</dd>
                    </div>
                @endif
            </dl>
        @endif
        <a href="{{ route('school.payouts.index', ['school' => $school->slug]) }}" class="btn-outline btn-sm !min-h-[48px] mt-5 w-full text-sm">All payouts</a>
    </section>

    {{-- Timeline --}}
    <section class="card p-5 sm:p-6 lg:col-span-2 lg:col-start-1 lg:row-span-3 lg:row-start-2" aria-labelledby="timeline-heading">
        <h2 id="timeline-heading" class="font-display text-lg font-bold tracking-tight">What happened</h2>
        <p class="mt-1 text-sm text-brand-slate">From the parent's payment to your bank account. Only recorded steps are shown.</p>
        @include('admin._timeline', ['steps' => $timeline])
    </section>

    {{-- Student --}}
    <section class="card p-5 sm:p-6 lg:col-start-3 lg:row-start-2" aria-labelledby="student-heading">
        <h2 id="student-heading" class="font-display text-lg font-bold tracking-tight">Student</h2>
        @if($t->hasStudent())
            <dl class="mt-3 divide-y divide-brand-fog text-sm">
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Name</dt>
                    <dd class="text-right font-semibold">{{ $t->student_name }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Admission no.</dt>
                    <dd class="text-right font-mono text-[13px] font-medium">{{ $t->student_admission_number ?? '—' }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Class</dt>
                    <dd class="text-right font-medium">{{ $t->student_class ?? '—' }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Session / term</dt>
                    <dd class="text-right font-medium">{{ $t->term_name ? $t->term_name.', '.$t->session_name : ($t->session_name ?? '—') }}</dd>
                </div>
            </dl>
            @if($t->student_id)
                <a href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $t->student_id]) }}" class="btn-outline btn-sm !min-h-[48px] mt-4 w-full text-sm">View student &amp; history</a>
            @else
                <p class="mt-3 text-sm text-brand-slate">This student is no longer on the roster; the details above are as recorded at payment.</p>
            @endif
        @else
            <p class="mt-3 text-sm text-brand-slate">No student was attached to this payment.</p>
        @endif
    </section>

    {{-- Fee --}}
    <section class="card p-5 sm:p-6 lg:col-start-3 lg:row-start-3" aria-labelledby="fee-heading">
        <h2 id="fee-heading" class="font-display text-lg font-bold tracking-tight">Fee</h2>
        <dl class="mt-3 divide-y divide-brand-fog text-sm">
            <div class="flex items-baseline justify-between gap-4 py-2.5">
                <dt class="text-brand-slate">Fee type</dt>
                <dd class="text-right font-semibold">{{ $t->subcategory_name ?? '—' }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 py-2.5">
                <dt class="text-brand-slate">Category</dt>
                <dd class="text-right font-medium">{{ $t->category_name ?? '—' }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 py-2.5">
                <dt class="text-brand-slate">Quantity</dt>
                <dd class="text-right font-medium tabular-nums">{{ $breakdown['quantity'] }} × {{ $money($breakdown['unit_price']) }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 py-2.5">
                <dt class="font-semibold text-brand-obsidian">Fee amount</dt>
                <dd class="text-right font-display text-base font-bold tabular-nums">{{ $money($breakdown['fee_subtotal']) }}</dd>
            </div>
        </dl>
    </section>

    {{-- Payer --}}
    <section class="card p-5 sm:p-6 lg:col-start-3 lg:row-start-4" aria-labelledby="payer-heading">
        <h2 id="payer-heading" class="font-display text-lg font-bold tracking-tight">Payer</h2>
        <dl class="mt-3 divide-y divide-brand-fog text-sm">
            <div class="flex items-baseline justify-between gap-4 py-2.5">
                <dt class="text-brand-slate">Name</dt>
                <dd class="text-right font-semibold">{{ $t->name ?? '—' }}</dd>
            </div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2.5">
                <dt class="text-brand-slate">Email</dt>
                <dd class="break-all text-right font-medium">{{ $t->email ?? '—' }}</dd>
            </div>
        </dl>
    </section>
</div>
@endsection
