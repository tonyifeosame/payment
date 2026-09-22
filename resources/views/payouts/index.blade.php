@extends('layouts.admin')

@section('title', 'Payouts')
@section('eyebrow', 'Money movement')
@section('heading', 'Payouts')
@section('subheading', 'Track money moving from student payments to your school.')

@section('content')
@php
    $s = ['school' => $school->slug];
    $money = fn ($n) => '₦'.number_format((float) $n, 2);
    $by = $summary['by_status'];
    $sum = function (array $statuses) use ($by) {
        $amount = 0.0; $count = 0;
        foreach ($statuses as $st) { $amount += $by[$st]['amount'] ?? 0; $count += $by[$st]['count'] ?? 0; }
        return ['amount' => round($amount, 2), 'count' => $count];
    };
    // Every figure is the school's share as recorded on the payout row — nothing is derived here.
    $cards = [
        ['Pending', $sum([\App\Models\Payout::PENDING]), 'pending', 'Waiting to be sent', 'text-brand-obsidian'],
        ['Processing', $sum([\App\Models\Payout::INITIATING, \App\Models\Payout::PROCESSING]), 'in_progress', 'Sent to the bank', 'text-brand-violet'],
        ['Paid', $sum([\App\Models\Payout::SUCCESS]), 'success', 'Reached your account', 'text-green-800'],
        ['Needs attention', $sum(\App\Http\Controllers\PayoutController::STATUS_GROUPS['attention']), 'attention', 'Failed or under review', 'text-red-700'],
    ];
    $groupLabels = ['in_progress' => 'Pending or processing', 'attention' => 'Needs attention'];
    $indexUrl = fn (array $except = []) => route('school.payouts.index', array_merge($s, Arr::except(request()->query(), array_merge($except, ['page']))));
    $activeFilters = [];
    if ($q !== '') { $activeFilters['q'] = ['Search', '“'.$q.'”']; }
    if ($status !== '') { $activeFilters['status'] = ['Status', $labels[$status] ?? $groupLabels[$status] ?? $status]; }
    if ($dateFrom) { $activeFilters['date_from'] = ['From', \Illuminate\Support\Carbon::parse($dateFrom)->format('d M Y')]; }
    if ($dateTo) { $activeFilters['date_to'] = ['To', \Illuminate\Support\Carbon::parse($dateTo)->format('d M Y')]; }
@endphp

{{-- Summary --}}
<div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" role="list" aria-label="Payout summary">
    @foreach($cards as [$label, $figure, $filter, $hint, $tone])
        <a href="{{ route('school.payouts.index', $s + ['status' => $filter]) }}" role="listitem"
           class="card flex min-h-[48px] flex-col p-4 hover:border-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 sm:p-5 {{ $status === $filter ? 'border-brand-violet ring-4 ring-brand-violet/15' : '' }}"
           aria-label="{{ $label }}: {{ $money($figure['amount']) }} across {{ $figure['count'] }} {{ Str::plural('payout', $figure['count']) }}. Show these payouts."
           @if($status === $filter) aria-current="true" @endif>
            <span class="text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate">{{ $label }}</span>
            <span class="mt-1 font-display text-xl font-extrabold tabular-nums tracking-tight sm:text-2xl {{ $tone }}">{{ $money($figure['amount']) }}</span>
            <span class="mt-1 text-xs text-brand-slate">{{ $figure['count'] }} {{ Str::plural('payout', $figure['count']) }} · {{ $hint }}</span>
        </a>
    @endforeach
</div>
<p class="mt-3 px-1 text-sm text-brand-slate">Every amount here is the fee amount due to your school for a confirmed payment.</p>

{{-- Filters --}}
<form method="GET" action="{{ route('school.payouts.index', $s) }}" class="card mt-6 p-5 sm:p-6" role="search" aria-labelledby="filters-heading">
    <h2 id="filters-heading" class="font-display text-lg font-bold tracking-tight">Filter payouts</h2>
    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-12">
        <div class="sm:col-span-2 lg:col-span-5">
            <label for="q" class="label">Search</label>
            <input id="q" name="q" type="search" value="{{ $q }}" class="input" placeholder="Payout or payment reference, student, payer" autocomplete="off" enterkeyhint="search">
        </div>
        <div class="lg:col-span-3">
            <label for="status" class="label">Status</label>
            <select id="status" name="status" class="input">
                <option value="">All statuses</option>
                <option value="in_progress" @selected($status === 'in_progress')>Pending or processing</option>
                <option value="attention" @selected($status === 'attention')>Needs attention</option>
                @foreach($labels as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:col-span-2">
            <label for="date_from" class="label">From</label>
            <input id="date_from" name="date_from" type="date" value="{{ $dateFrom }}" class="input">
        </div>
        <div class="lg:col-span-2">
            <label for="date_to" class="label">To</label>
            <input id="date_to" name="date_to" type="date" value="{{ $dateTo }}" class="input">
        </div>
        <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:justify-end lg:col-span-12">
            <a class="btn-outline sm:w-auto" href="{{ route('school.payouts.index', $s) }}">Reset filters</a>
            <button class="btn-obsidian sm:w-auto" type="submit">Apply filters</button>
        </div>
    </div>
    @if($activeFilters)
        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-brand-ash/60 pt-5" role="group" aria-label="Active filters">
            <span class="mr-1 text-xs font-semibold uppercase tracking-[0.08em] text-brand-violet">{{ count($activeFilters) }} {{ Str::plural('filter', count($activeFilters)) }} active</span>
            @foreach($activeFilters as $chipKey => [$chipLabel, $chipValue])
                <a href="{{ $indexUrl([$chipKey]) }}" class="inline-flex min-h-[48px] items-center gap-1.5 rounded-full border border-brand-violet/30 bg-brand-violet/10 py-1 pl-4 pr-3 text-sm font-medium text-brand-violet hover:border-brand-violet hover:bg-brand-violet/15 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">
                    <span><span class="sr-only">Remove filter </span>{{ $chipLabel }}: {{ $chipValue }}</span>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </a>
            @endforeach
        </div>
    @endif
</form>

{{-- Result summary --}}
<div class="mb-3 mt-6 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
    <p class="text-sm text-brand-slate">
        <span class="font-display text-lg font-bold text-brand-obsidian">{{ number_format($payouts->total()) }}</span> {{ Str::plural('payout', $payouts->total()) }}@if($activeFilters) match your filters @endif
    </p>
    @if($payouts->hasPages())
        <p class="text-sm text-brand-slate">Page {{ $payouts->currentPage() }} of {{ $payouts->lastPage() }}</p>
    @endif
</div>

<x-admin.table
    :columns="$payouts->isEmpty() ? [] : [['Date', 'left', 'xl:w-[13%]'], ['Payment', 'left', 'xl:w-[23%]'], ['Reference', 'left', 'xl:w-[19%]'], ['Amount', 'right', 'xl:w-[12%]'], ['Status', 'left', 'xl:w-[13%]'], ['Updated', 'left', 'xl:w-[10%]'], ['Actions', 'actions', 'xl:w-[10%]']]"
    caption="Payouts to {{ $school->name }}, newest first"
    class="xl:[&_table]:w-full xl:[&_table]:table-fixed md:[&_.th]:px-3 md:[&_.td]:px-3"
    stacked>
    @forelse($payouts as $payout)
        @php $t = $payout->transaction; $createdAt = \App\Support\BusinessTime::display($payout->created_at); $updatedAt = \App\Support\BusinessTime::display($payout->updated_at); @endphp
        <tr>
            <td class="td" data-label="Date">
                <div class="min-w-0">
                    <time datetime="{{ $createdAt?->toIso8601String() }}" class="whitespace-nowrap font-medium">{{ $createdAt?->format('d M Y') }}</time>
                    <span class="block text-xs text-brand-slate">{{ $createdAt?->format('H:i') }}</span>
                </div>
            </td>
            <td class="td" data-label="Payment">
                <div class="min-w-0">
                    @if($t)
                        <span class="block font-semibold">{{ $t->student_name ?? $t->name ?? $t->email ?? '—' }}</span>
                        <span class="block text-xs text-brand-slate">{{ $t->subcategory_name ?? $t->category_name ?? 'Payment' }}@if($t->student_name && $t->name) · paid by {{ $t->name }}@endif</span>
                    @else
                        <span class="text-brand-slate">No payment attached</span>
                    @endif
                </div>
            </td>
            <td class="td" data-label="Reference">
                <div class="min-w-0">
                    @if($t)<span class="block break-all font-mono text-[12px]">{{ $t->reference }}</span>@endif
                    <span class="block break-all font-mono text-[11px] leading-4 text-brand-slate">Payout {{ $payout->reference ?? '—' }}</span>
                </div>
            </td>
            <td class="td text-right lg:whitespace-nowrap" data-label="Amount">
                <div class="min-w-0">
                    @if($payout->status === \App\Models\Payout::NEEDS_REVIEW && (float) $payout->amount === 0.0)
                        <span class="text-sm text-brand-slate">Under review</span>
                    @else
                        <span class="whitespace-nowrap font-display text-base font-bold tabular-nums">{{ $money($payout->amount) }}</span>
                    @endif
                </div>
            </td>
            <td class="td" data-label="Status">@include('admin._badge', ['status' => $payout->status, 'label' => $labels[$payout->status] ?? ucfirst($payout->status)])</td>
            <td class="td" data-label="Updated">
                <div class="min-w-0">
                    <time datetime="{{ $updatedAt?->toIso8601String() }}" class="text-sm">{{ $updatedAt?->format('d M Y') }}</time>
                    <span class="block text-xs text-brand-slate">{{ $updatedAt?->format('H:i') }}</span>
                </div>
            </td>
            <td class="td td-actions" data-label="">
                <a class="btn-outline btn-sm !min-h-[48px] w-full !px-4 text-sm md:w-auto" href="{{ route('school.payouts.show', $s + ['payout' => $payout->id]) }}">View<span class="sr-only"> payout {{ $payout->reference }}</span></a>
            </td>
        </tr>
    @empty
        <x-slot:empty>
            @if($status === 'pending' && ! $q && ! $dateFrom && ! $dateTo)
                <x-admin.empty title="No pending payouts" description="Nothing is waiting to be sent. New payouts appear here as soon as a payment is confirmed." icon="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z">
                    <a class="btn-outline" href="{{ route('school.payouts.index', $s) }}">Show all payouts</a>
                </x-admin.empty>
            @elseif($status === 'attention' && ! $q && ! $dateFrom && ! $dateTo)
                <x-admin.empty title="Nothing needs your attention" description="No payout has failed or is under review." icon="M5 12.5l4.5 4.5L19 7">
                    <a class="btn-outline" href="{{ route('school.payouts.index', $s) }}">Show all payouts</a>
                </x-admin.empty>
            @elseif($activeFilters)
                <x-admin.empty title="No payouts match these filters" description="Try a wider date range, another status or a different reference." icon="M21 21l-4.3-4.3M11 18a7 7 0 100-14 7 7 0 000 14z">
                    <a class="btn-outline" href="{{ route('school.payouts.index', $s) }}">Reset filters</a>
                </x-admin.empty>
            @else
                <x-admin.empty title="No payouts yet" description="A payout is created the moment a payment is confirmed. Your share of each confirmed payment will appear here." icon="M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2zm3 8h3">
                    <a class="btn-outline" href="{{ route('school.transactions.index', $s) }}">View transactions</a>
                </x-admin.empty>
            @endif
        </x-slot:empty>
    @endforelse
    @if($payouts->hasPages())
        <x-slot:footer>{{ $payouts->links() }}</x-slot:footer>
    @endif
</x-admin.table>
@endsection
