@extends('layouts.admin')

@php
    $s = ['school' => $school->slug];
    $money = fn ($v) => '₦'.number_format((float) $v, 2);
    $t = $transaction;
    $isPaid = $payout->status === \App\Models\Payout::SUCCESS;
    $underReview = $payout->status === \App\Models\Payout::NEEDS_REVIEW;
    $account = $school->account_number ? '····'.substr((string) $school->account_number, -4) : null;
@endphp

@section('title', 'Payout '.($payout->reference ?? $payout->id))
@section('eyebrow', 'Money movement · Payout')
@section('heading', $underReview && (float) $payout->amount === 0.0 ? 'Payout under review' : $money($payout->amount))
@section('subheading', ($t ? 'Your share of '.($t->subcategory_name ?? $t->category_name ?? 'a payment').($t->student_name ? ' for '.$t->student_name : '').' · ' : '').'Ref '.($payout->reference ?? '—'))
@section('actions')
    <a href="{{ route('school.payouts.index', $s) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        All payouts
    </a>
    @if($t)
        <a href="{{ route('school.transactions.show', $s + ['transaction' => $t->id]) }}" class="btn-obsidian">View payment</a>
    @endif
@endsection

@section('content')
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

    {{-- Payout state --}}
    <section class="card p-5 sm:p-6 lg:col-span-2" aria-labelledby="payout-heading">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <h2 id="payout-heading" class="font-display text-lg font-bold tracking-tight">Payout</h2>
            @include('admin._badge', ['status' => $payout->status, 'label' => $labels[$payout->status] ?? ucfirst($payout->status)])
        </div>
        <p class="mt-3 font-display text-xl font-bold tracking-tight {{ $state['group'] === 'attention' ? 'text-red-700' : ($state['group'] === 'paid' ? 'text-green-800' : '') }}">{{ $state['headline'] }}</p>
        <p class="mt-1 text-sm text-brand-slate">{{ $state['note'] }}</p>

        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-2xl bg-brand-fog p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate">Payout amount</p>
                <p class="mt-1 font-display text-2xl font-extrabold tabular-nums tracking-tight">
                    @if($underReview && (float) $payout->amount === 0.0)<span class="text-base font-bold text-brand-slate">Being determined</span>@else{{ $money($payout->amount) }}@endif
                </p>
            </div>
            @if($breakdown)
                <div class="rounded-2xl border border-brand-ash/60 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate">Fee amount</p>
                    <p class="mt-1 font-display text-2xl font-extrabold tabular-nums tracking-tight">{{ $money($breakdown['fee_subtotal']) }}</p>
                </div>
            @endif
        </div>

        <dl class="mt-5 divide-y divide-brand-fog border-t border-brand-fog text-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                <dt class="text-brand-slate">Payout reference</dt>
                <dd class="break-all font-mono text-[13px] font-medium">{{ $payout->reference ?? '—' }}</dd>
            </div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                <dt class="text-brand-slate">Created</dt>
                <dd class="font-medium"><time datetime="{{ $payout->created_at?->toIso8601String() }}">{{ $payout->created_at?->format('d M Y, H:i') }}</time></dd>
            </div>
            @if($payout->initiated_at)
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                    <dt class="text-brand-slate">Sent to bank</dt>
                    <dd class="font-medium"><time datetime="{{ $payout->initiated_at->toIso8601String() }}">{{ $payout->initiated_at->format('d M Y, H:i') }}</time></dd>
                </div>
            @endif
            @if($payout->completed_at)
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                    <dt class="text-brand-slate">{{ $isPaid ? 'Paid' : 'Closed' }}</dt>
                    <dd class="font-medium"><time datetime="{{ $payout->completed_at->toIso8601String() }}">{{ $payout->completed_at->format('d M Y, H:i') }}</time></dd>
                </div>
            @endif
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                <dt class="text-brand-slate">Last updated</dt>
                <dd class="font-medium"><time datetime="{{ $payout->updated_at?->toIso8601String() }}">{{ $payout->updated_at?->format('d M Y, H:i') }}</time></dd>
            </div>
            @if($school->bank || $account)
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                    <dt class="text-brand-slate">Destination</dt>
                    <dd class="text-right font-medium">{{ $school->bank }}@if($account) <span class="font-mono">{{ $account }}</span>@endif @if($school->account_name)<span class="block text-xs font-normal text-brand-slate">{{ $school->account_name }}</span>@endif</dd>
                </div>
            @endif
        </dl>

        @if($state['group'] === 'attention')
            <div class="mt-5 rounded-2xl border border-brand-ash/60 bg-brand-fog/60 p-4 text-sm">
                <p class="font-semibold">What you can do</p>
                <p class="mt-1 text-brand-slate">No action in FEYRA changes a payout's state; that only happens when the bank confirms an outcome. If this stays as it is, contact support and quote the payout reference <span class="font-mono text-brand-obsidian">{{ $payout->reference }}</span>{{ $t ? ' and payment reference '.$t->reference : '' }}.</p>
            </div>
        @endif
    </section>

    {{-- Payment --}}
    <section class="card p-5 sm:p-6 lg:col-start-3 lg:row-start-1" aria-labelledby="payment-heading">
        <h2 id="payment-heading" class="font-display text-lg font-bold tracking-tight">Payment</h2>
        @if($t)
            <dl class="mt-3 divide-y divide-brand-fog text-sm">
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Paid on</dt>
                    <dd class="text-right font-medium"><time datetime="{{ ($t->paid_at ?? $t->created_at)?->toIso8601String() }}">{{ ($t->paid_at ?? $t->created_at)?->format('d M Y, H:i') }}</time></dd>
                </div>
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2.5">
                    <dt class="text-brand-slate">Reference</dt>
                    <dd class="break-all text-right font-mono text-[13px] font-medium">{{ $t->reference }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Student</dt>
                    <dd class="text-right font-medium">
                        @if($t->student_id)
                            <a href="{{ route('school.students.show', $s + ['student' => $t->student_id]) }}" class="rounded text-brand-obsidian hover:text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">{{ $t->student_name }}</a>
                        @else
                            {{ $t->student_name ?? 'No student' }}
                        @endif
                        @if($t->student_admission_number)<span class="block font-mono text-xs font-normal text-brand-slate">{{ $t->student_admission_number }}</span>@endif
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Fee</dt>
                    <dd class="text-right font-medium">{{ $t->subcategory_name ?? '—' }}@if($t->category_name)<span class="block text-xs font-normal text-brand-slate">{{ $t->category_name }}</span>@endif</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Payer</dt>
                    <dd class="text-right font-medium">{{ $t->name ?? '—' }}@if($t->email)<span class="block break-all text-xs font-normal text-brand-slate">{{ $t->email }}</span>@endif</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Fee amount</dt>
                    <dd class="text-right font-display text-base font-bold tabular-nums">{{ $money($breakdown['fee_subtotal']) }}</dd>
                </div>
            </dl>
            @if($t->status === \App\Models\Transaction::STATUS_SUCCESS)
                <a href="{{ route('payment.receipt', $t->id) }}" class="btn-outline btn-sm !min-h-[48px] mt-4 w-full text-sm">View receipt</a>
            @endif
        @else
            <p class="mt-2 text-sm text-brand-slate">This payout is not linked to a single payment (an older batch record).</p>
        @endif
    </section>

    {{-- Timeline --}}
    @if($timeline)
        <section class="card p-5 sm:p-6 lg:col-span-2 lg:col-start-1 lg:row-start-2" aria-labelledby="timeline-heading">
            <h2 id="timeline-heading" class="font-display text-lg font-bold tracking-tight">What happened</h2>
            <p class="mt-1 text-sm text-brand-slate">From the parent's payment to your bank account. Only recorded steps are shown.</p>
            @include('admin._timeline', ['steps' => $timeline])
        </section>
    @endif
</div>
@endsection
