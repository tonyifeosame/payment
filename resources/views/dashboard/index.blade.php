@extends('layouts.admin')

@section('title', 'Dashboard')
@section('eyebrow', 'Overview')
@section('heading', 'Dashboard')
@section('subheading')
    {{ $school->name }}'s payment overview
    @if($selectedTerm)
        · {{ $selectedTerm->name }}, {{ $selectedTerm->session->name }}
    @else
        · all-time figures until you create an academic session
    @endif
@endsection

@section('actions')
    @if($terms->isNotEmpty())
        {{-- Term context for the term tile and the category breakdown. --}}
        <form method="GET" action="{{ route('school.dashboard', ['school' => $school->slug]) }}" class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-end">
            <div class="min-w-0 sm:w-64">
                <label for="term" class="field-label">Term</label>
                <select id="term" name="term" class="field-input" onchange="this.form.submit()">
                    @foreach($terms as $t)
                        <option value="{{ $t->id }}" @selected($selectedTerm && $selectedTerm->id === $t->id)>{{ $t->name }}, {{ $t->session->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-outline">Show</button>
        </form>
    @else
        <a href="{{ route('school.sessions.index', ['school' => $school->slug]) }}" class="btn-obsidian">Create academic session</a>
    @endif
@endsection

@section('content')
@php
    $s = ['school' => $school->slug];
    $money = fn ($n) => '₦'.number_format((float) $n, 2);
    $tz = \App\Services\SchoolDashboardService::reportingTimezone();
    $pendingCount = (int) ($stats['status_counts']['pending'] ?? 0);
    $failedCount = (int) (($stats['status_counts']['failed'] ?? 0) + ($stats['status_counts']['mismatch'] ?? 0));
    $hasSessions = $terms->isNotEmpty();
    $hasFees = $school->subcategories()->exists();
    $hasStudents = $school->students()->exists();
    $setupDone = $hasSessions && $hasFees && $hasStudents;
    $by = $stats['payouts']['by_status'];
    $sum = function (array $statuses) use ($by) {
        $amount = 0.0; $count = 0;
        foreach ($statuses as $st) { $amount += $by[$st]['amount'] ?? 0; $count += $by[$st]['count'] ?? 0; }
        return ['amount' => round($amount, 2), 'count' => $count];
    };
    // Same groupings as the Payouts page; every figure is the school's fee amount.
    $payoutRows = [
        ['Pending', $sum([\App\Models\Payout::PENDING]), 'pending', ''],
        ['Processing', $sum([\App\Models\Payout::INITIATING, \App\Models\Payout::PROCESSING]), 'in_progress', 'text-brand-violet'],
        ['Paid', $sum([\App\Models\Payout::SUCCESS]), 'success', 'text-green-800'],
        ['Needs attention', $sum([\App\Models\Payout::FAILED, \App\Models\Payout::NEEDS_REVIEW]), 'attention', 'text-red-700'],
    ];
    $summaryCards = [
        ['Today', $stats['today'], 'Since midnight'],
        ['This week', $stats['week'], 'Since Monday'],
        [$selectedTerm ? $selectedTerm->name : 'Current term', $selectedTerm ? $stats['term_totals'] : null, $selectedTerm ? $selectedTerm->session->name : 'No term selected'],
        ['All time', $stats['all_time'], 'Every successful payment'],
    ];
@endphp

{{-- Setup: only while something is missing --}}
@unless($setupDone)
    <section class="card mb-6 p-5 sm:p-6" aria-labelledby="setup-heading">
        <h2 id="setup-heading" class="font-display text-lg font-bold tracking-tight">Finish setting up</h2>
        <p class="mt-1 text-sm text-brand-slate">Parents can pay once your school has a session, at least one fee type and its students.</p>
        <ol class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            @foreach([
                [$hasSessions, 'Academic session', 'Create the session and its terms', route('school.sessions.index', $s)],
                [$hasFees, 'Fee types', 'Add the fees parents can pay', route('school.subcategories.index', $s)],
                [$hasStudents, 'Students', 'Add your first student', route('school.students.index', $s)],
            ] as $i => [$done, $stepLabel, $stepHint, $href])
                <li>
                    <a href="{{ $href }}" class="flex min-h-[64px] items-center gap-3 rounded-2xl border p-3 hover:border-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 {{ $done ? 'border-green-200 bg-green-50' : 'border-brand-ash/60 bg-white' }}">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full font-display text-sm font-bold {{ $done ? 'bg-green-600 text-white' : 'bg-brand-fog text-brand-slate' }}" aria-hidden="true">
                            @if($done)<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>@else{{ $i + 1 }}@endif
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold">{{ $stepLabel }}<span class="sr-only">: {{ $done ? 'done' : 'to do' }}</span></span>
                            <span class="block text-xs text-brand-slate">{{ $done ? 'Done' : $stepHint }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>
@endunless

{{-- Money summary --}}
<section aria-labelledby="summary-heading">
    <h2 id="summary-heading" class="sr-only">Collections</h2>
    <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" role="list">
        {{-- Loop variables are prefixed: @include shares this scope, and a bare label
             variable would leak into admin._badge below. --}}
        @foreach($summaryCards as [$cardLabel, $bucket, $cardHint])
            <div class="card flex flex-col p-4 sm:p-5" role="listitem">
                <span class="text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate">{{ $cardLabel }}</span>
                @if($bucket === null)
                    <span class="mt-1 font-display text-lg font-bold text-brand-slate">—</span>
                    <span class="mt-1 text-xs text-brand-slate">{{ $cardHint }}</span>
                @else
                    <span class="mt-1 font-display text-xl font-extrabold tabular-nums tracking-tight sm:text-2xl">{{ $money($bucket['net']) }}</span>
                    <span class="mt-1 text-xs text-brand-slate">{{ $bucket['count'] }} {{ Str::plural('payment', $bucket['count']) }} · {{ $cardHint }}</span>
                @endif
            </div>
        @endforeach
    </div>
    <p class="mt-3 px-1 text-sm text-brand-slate">Amounts are the fee amounts collected for your school from successful payments. Times follow {{ str_replace('_', ' ', $tz) }}.</p>
</section>

<div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3 xl:items-start">
    {{-- Recent payments --}}
    <section class="min-w-0 xl:col-span-2" aria-labelledby="recent-heading">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
            <h2 id="recent-heading" class="font-display text-lg font-bold tracking-tight">Recent payments</h2>
            <a href="{{ route('school.transactions.index', $s) }}" class="inline-flex min-h-[48px] items-center rounded px-1 text-sm font-semibold text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">View all payments</a>
        </div>
        <x-admin.table
            :columns="$stats['recent']->isEmpty() ? [] : [['Date', 'left', 'xl:w-[16%]'], ['Student / payer', 'left', 'xl:w-[28%]'], ['Fee', 'left', 'xl:w-[22%]'], ['Amount', 'right', 'xl:w-[14%]'], ['Status', 'left', 'hidden min-[1400px]:table-cell min-[1400px]:w-[9%]'], ['Actions', 'actions', 'xl:w-[11%]']]"
            caption="The 10 most recent successful payments"
            class="xl:[&_table]:w-full xl:[&_table]:table-fixed md:[&_.th]:px-3 md:[&_.td]:px-3"
            stacked>
            @forelse($stats['recent'] as $t)
                @php $when = ($t->paid_at ?? $t->created_at)?->timezone($tz); @endphp
                <tr>
                    <td class="td" data-label="Date">
                        <time datetime="{{ $when?->toIso8601String() }}">{{ $when?->format('d M, H:i') }}</time>
                    </td>
                    <td class="td" data-label="Student / payer">
                        <div class="min-w-0">
                            <span class="block font-semibold">{{ $t->student_name ?? $t->name ?? $t->email }}</span>
                            <span class="block text-xs text-brand-slate">
                                @if($t->student_admission_number)<span class="font-mono">{{ $t->student_admission_number }}</span>@if($t->student_name && $t->name) · paid by {{ $t->name }}@endif
                                @elseif($t->student_name && $t->name)paid by {{ $t->name }}@else{{ $t->email }}@endif
                            </span>
                        </div>
                    </td>
                    <td class="td" data-label="Fee">
                        <div class="min-w-0">
                            <span class="block font-medium">{{ $t->subcategory_name ?? $t->category_name ?? '—' }}</span>
                            <span class="block text-xs text-brand-slate">{{ $t->category_name }}@if($t->term_name) · {{ $t->term_name }}@endif</span>
                        </div>
                    </td>
                    <td class="td text-right lg:whitespace-nowrap" data-label="Amount">
                        <span class="whitespace-nowrap font-display text-base font-bold tabular-nums">{{ $money($t->fee_amount ?? $t->receiptBreakdown()['fee_subtotal']) }}</span>
                    </td>
                    <td class="td hidden max-md:flex min-[1400px]:table-cell" data-label="Status">@include('admin._badge', ['status' => $t->status])</td>
                    <td class="td td-actions" data-label="">
                        <a class="btn-outline btn-sm !min-h-[48px] w-full !px-3 text-sm md:w-auto" href="{{ route('school.transactions.show', $s + ['transaction' => $t->id]) }}">View<span class="sr-only"> payment {{ $t->reference }}</span></a>
                    </td>
                </tr>
            @empty
                <x-slot:empty>
                    <x-admin.empty title="No payments yet" description="Successful payments appear here as soon as parents pay on your payment page." icon="M4 8h16M4 16h16M8 4l-4 4 4 4M16 12l4 4-4 4" compact>
                        <a class="btn-outline" href="{{ route('school.share.index', $s) }}">Share your payment page</a>
                    </x-admin.empty>
                </x-slot:empty>
            @endforelse
        </x-admin.table>

        {{-- Attention: secondary, never the focus --}}
        @if($pendingCount > 0 || $failedCount > 0)
            <p class="mt-3 px-1 text-sm text-brand-slate">
                @if($pendingCount > 0)
                    <a href="{{ route('school.transactions.index', $s + ['status' => 'pending']) }}" class="rounded font-semibold text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">{{ $pendingCount }} pending {{ Str::plural('payment', $pendingCount) }}</a> awaiting confirmation.
                @endif
                @if($failedCount > 0)
                    <a href="{{ route('school.transactions.index', $s + ['status' => 'failed']) }}" class="rounded hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">{{ $failedCount }} {{ Str::plural('payment', $failedCount) }} not completed</a>.
                @endif
            </p>
        @endif
    </section>

    <div class="space-y-6">
        {{-- Payouts --}}
        <section class="card p-5 sm:p-6" aria-labelledby="payouts-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="payouts-heading" class="font-display text-lg font-bold tracking-tight">Payouts</h2>
                <a href="{{ route('school.payouts.index', $s) }}" class="inline-flex min-h-[48px] items-center rounded px-1 text-sm font-semibold text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">View payouts</a>
            </div>
            <p class="mt-1 text-sm text-brand-slate">Money moving from confirmed payments to your bank account.</p>
            <ul class="mt-4 divide-y divide-brand-fog">
                @foreach($payoutRows as [$rowLabel, $figure, $filter, $tone])
                    <li>
                        <a href="{{ route('school.payouts.index', $s + ['status' => $filter]) }}" class="flex min-h-[56px] items-center justify-between gap-4 rounded-xl px-1 py-2 hover:bg-brand-fog/60 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium">{{ $rowLabel }}</span>
                                <span class="block text-xs text-brand-slate">{{ $figure['count'] }} {{ Str::plural('payout', $figure['count']) }}</span>
                            </span>
                            <span class="font-display text-base font-bold tabular-nums {{ $tone }}">{{ $money($figure['amount']) }}</span>
                        </a>
                    </li>
                @endforeach
                @if(($stats['payouts']['reversed']['count'] ?? 0) > 0)
                    <li>
                        <a href="{{ route('school.payouts.index', $s + ['status' => 'reversed']) }}" class="flex min-h-[56px] items-center justify-between gap-4 rounded-xl px-1 py-2 hover:bg-brand-fog/60 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">
                            <span class="min-w-0"><span class="block text-sm font-medium">Reversed</span><span class="block text-xs text-brand-slate">{{ $stats['payouts']['reversed']['count'] }} {{ Str::plural('payout', $stats['payouts']['reversed']['count']) }}</span></span>
                            <span class="font-display text-base font-bold tabular-nums text-brand-slate">{{ $money($stats['payouts']['reversed']['amount']) }}</span>
                        </a>
                    </li>
                @endif
            </ul>
            @if($payoutRows[3][1]['count'] > 0)
                <p class="mt-3 rounded-2xl bg-brand-fog px-4 py-3 text-sm text-brand-slate"><span class="font-semibold text-brand-obsidian">{{ $payoutRows[3][1]['count'] }} {{ Str::plural('payout', $payoutRows[3][1]['count']) }} {{ $payoutRows[3][1]['count'] === 1 ? 'needs' : 'need' }} attention</span> — open it for what happened and what to quote to support.</p>
            @endif
        </section>

        {{-- Quick actions --}}
        <section class="card p-5 sm:p-6" aria-labelledby="actions-heading">
            <h2 id="actions-heading" class="font-display text-lg font-bold tracking-tight">Quick actions</h2>
            <ul class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-1">
                @foreach([
                    ['Add student', route('school.students.create', $s)],
                    ['Add fee type', route('school.subcategories.create', $s)],
                    ['Sessions & terms', route('school.sessions.index', $s)],
                    ['Share payment page', route('school.share.index', $s)],
                ] as [$actionLabel, $href])
                    <li><a href="{{ $href }}" class="btn-outline w-full justify-between">{{ $actionLabel }}<svg class="h-4 w-4 text-brand-slate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a></li>
                @endforeach
                <li><a href="{{ $school->paymentUrl() }}" target="_blank" rel="noopener" class="btn-outline w-full justify-between">Open payment page<span aria-hidden="true">↗</span><span class="sr-only">(opens in a new tab)</span></a></li>
            </ul>
        </section>
    </div>
</div>

{{-- Collections by category --}}
<section class="mt-6" aria-labelledby="category-heading">
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
        <h2 id="category-heading" class="font-display text-lg font-bold tracking-tight">Collections by category</h2>
        <p class="text-sm text-brand-slate">{{ $selectedTerm ? $selectedTerm->name.', '.$selectedTerm->session->name : 'All time' }}</p>
    </div>
    <x-admin.table
        :columns="$stats['by_category']->isEmpty() ? [] : [['Category'], ['Payments', 'right', 'xl:w-[20%]'], ['Collected', 'right', 'xl:w-[25%]']]"
        caption="Successful payments grouped by fee category"
        class="xl:[&_table]:w-full xl:[&_table]:table-fixed md:[&_.th]:px-3 md:[&_.td]:px-3"
        stacked>
        @forelse($stats['by_category'] as $row)
            <tr>
                <td class="td" data-label="Category"><span class="font-semibold">{{ $row->category }}</span></td>
                <td class="td text-right tabular-nums" data-label="Payments">{{ (int) $row->count }}</td>
                <td class="td text-right" data-label="Collected"><span class="whitespace-nowrap font-display text-base font-bold tabular-nums">{{ $money($row->net) }}</span></td>
            </tr>
        @empty
            <x-slot:empty>
                <x-admin.empty title="Nothing collected yet" description="{{ $selectedTerm ? 'No successful payments in '.$selectedTerm->name.', '.$selectedTerm->session->name.' yet.' : 'Successful payments will be grouped by category here.' }}" icon="M4 6h16M4 12h16M4 18h10" compact />
            </x-slot:empty>
        @endforelse
    </x-admin.table>
</section>
@endsection
