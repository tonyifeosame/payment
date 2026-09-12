@extends('layouts.admin')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('subheading')
    @if($selectedTerm)
        Showing {{ $selectedTerm->name }}, {{ $selectedTerm->session->name }}
    @else
        All-time figures — create a session to track collections by term
    @endif
@endsection

@section('actions')
    @if($terms->isNotEmpty())
        <form method="GET" class="flex items-center gap-2">
            <label for="term" class="text-sm font-semibold text-slate-600">Term</label>
            <select id="term" name="term" class="input !w-auto" onchange="this.form.submit()">
                @foreach($terms as $t)
                    <option value="{{ $t->id }}" @selected($selectedTerm && $selectedTerm->id === $t->id)>{{ $t->name }}, {{ $t->session->name }}</option>
                @endforeach
            </select>
        </form>
    @else
        <a href="{{ route('school.sessions.index', ['school' => $school->slug]) }}" class="btn-primary">Create academic session</a>
    @endif
@endsection

@section('content')
@php
    $money = fn ($n) => '₦'.number_format((float) $n, 2);
    $pending = ($stats['status_counts']['pending'] ?? 0);
    $failed = ($stats['status_counts']['failed'] ?? 0) + ($stats['status_counts']['mismatch'] ?? 0);
@endphp

@if($school->students()->doesntExist() || $terms->isEmpty() || $school->subcategories()->doesntExist())
    <div class="card p-5 mb-6 border-blue-200 bg-blue-50/60">
        <p class="font-bold text-slate-900 mb-2">Set-up checklist</p>
        <ol class="grid sm:grid-cols-3 gap-3 text-sm">
            <li class="flex items-center gap-2 {{ $terms->isNotEmpty() ? 'text-green-700' : 'text-slate-700' }}">
                <span>{{ $terms->isNotEmpty() ? '✓' : '1.' }}</span>
                <a class="underline" href="{{ route('school.sessions.index', ['school' => $school->slug]) }}">Create the academic session and term</a>
            </li>
            <li class="flex items-center gap-2 {{ $school->subcategories()->exists() ? 'text-green-700' : 'text-slate-700' }}">
                <span>{{ $school->subcategories()->exists() ? '✓' : '2.' }}</span>
                <a class="underline" href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}">Add your fees</a>
            </li>
            <li class="flex items-center gap-2 {{ $school->students()->exists() ? 'text-green-700' : 'text-slate-700' }}">
                <span>{{ $school->students()->exists() ? '✓' : '3.' }}</span>
                <a class="underline" href="{{ route('school.students.index', ['school' => $school->slug]) }}">Add your students</a>
            </li>
        </ol>
    </div>
@endif

<!-- Collection tiles -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    @foreach([
        ['Today', $stats['today'], 'from-blue-600 to-indigo-600'],
        ['This week', $stats['week'], 'from-purple-600 to-pink-600'],
        [$selectedTerm ? $selectedTerm->name.' '.$selectedTerm->session->name : 'Selected term', $selectedTerm ? $stats['term_totals'] : null, 'from-emerald-600 to-teal-600'],
        ['All time', $stats['all_time'], 'from-slate-700 to-slate-900'],
    ] as [$label, $bucket, $gradient])
        <div class="card p-5 relative overflow-hidden">
            <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r {{ $gradient }}"></div>
            <p class="text-xs font-black uppercase tracking-wider text-slate-500">{{ $label }}</p>
            @if($bucket === null)
                <p class="text-slate-500 text-sm mt-3">No term selected.</p>
            @else
                <p class="text-2xl font-black text-slate-900 mt-2">{{ $money($bucket['net']) }}</p>
                <p class="text-xs text-slate-500 mt-1">{{ $bucket['count'] }} {{ Str::plural('payment', $bucket['count']) }} · {{ $money($bucket['gross']) }} charged incl. service fee</p>
            @endif
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <div class="card p-5">
        <p class="text-xs font-black uppercase tracking-wider text-slate-500">Successful payments</p>
        <p class="text-3xl font-black text-green-700 mt-2">{{ $stats['status_counts']['success'] ?? 0 }}</p>
        <p class="text-xs text-slate-500 mt-1">all time</p>
    </div>
    <div class="card p-5">
        <p class="text-xs font-black uppercase tracking-wider text-slate-500">Pending / not completed</p>
        <p class="text-3xl font-black text-amber-600 mt-2">{{ $pending }}</p>
        <a class="text-xs text-blue-700 underline mt-1 inline-block" href="{{ route('school.transactions.index', ['school' => $school->slug, 'status' => 'pending']) }}">View pending</a>
    </div>
    <div class="card p-5">
        <p class="text-xs font-black uppercase tracking-wider text-slate-500">Failed / needs attention</p>
        <p class="text-3xl font-black text-red-600 mt-2">{{ $failed }}</p>
        <a class="text-xs text-blue-700 underline mt-1 inline-block" href="{{ route('school.transactions.index', ['school' => $school->slug, 'status' => 'failed']) }}">View failed</a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <!-- By category -->
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 class="font-bold text-slate-900">Collections by category</h2>
            <span class="text-xs text-slate-500">{{ $selectedTerm ? 'selected term' : 'all time' }}</span>
        </div>
        <table class="min-w-full">
            <tbody class="divide-y divide-slate-100">
                @forelse($stats['by_category'] as $row)
                    <tr>
                        <td class="td font-semibold">{{ $row->category }}<span class="block text-xs text-slate-500 font-normal">{{ $row->count }} {{ Str::plural('payment', $row->count) }}</span></td>
                        <td class="td text-right font-bold">{{ $money($row->net) }}</td>
                    </tr>
                @empty
                    <tr><td class="td text-slate-500" colspan="2">No successful payments yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Payout status -->
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 class="font-bold text-slate-900">Payouts to your bank</h2>
            <a class="text-xs text-blue-700 underline" href="{{ route('school.payouts.index', ['school' => $school->slug]) }}">Ledger</a>
        </div>
        @php $p = $stats['payouts']; @endphp
        <dl class="divide-y divide-slate-100">
            <div class="flex justify-between px-5 py-3"><dt class="text-sm text-slate-600">Paid to bank</dt><dd class="font-bold text-green-700">{{ $money($p['paid']['amount']) }} <span class="text-xs text-slate-500 font-normal">({{ $p['paid']['count'] }})</span></dd></div>
            <div class="flex justify-between px-5 py-3"><dt class="text-sm text-slate-600">On the way</dt><dd class="font-bold text-amber-600">{{ $money($p['in_progress']['amount']) }} <span class="text-xs text-slate-500 font-normal">({{ $p['in_progress']['count'] }})</span></dd></div>
            <div class="flex justify-between px-5 py-3"><dt class="text-sm text-slate-600">Needs attention</dt><dd class="font-bold text-red-600">{{ $money($p['attention']['amount']) }} <span class="text-xs text-slate-500 font-normal">({{ $p['attention']['count'] }})</span></dd></div>
            @if($p['reversed']['count'] > 0)
                <div class="flex justify-between px-5 py-3"><dt class="text-sm text-slate-600">Reversed</dt><dd class="font-bold text-slate-700">{{ $money($p['reversed']['amount']) }} <span class="text-xs text-slate-500 font-normal">({{ $p['reversed']['count'] }})</span></dd></div>
            @endif
        </dl>
        <p class="px-5 pb-4 text-xs text-slate-500">Payout amounts are the school's share only; the service fee is never paid out.</p>
    </div>

    <!-- Recent payments -->
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 class="font-bold text-slate-900">Recent payments</h2>
            <a class="text-xs text-blue-700 underline" href="{{ route('school.transactions.index', ['school' => $school->slug]) }}">All</a>
        </div>
        <ul class="divide-y divide-slate-100">
            @forelse($stats['recent'] as $t)
                <li class="px-5 py-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-900 truncate">{{ $t->student_name ?? $t->name ?? $t->email }}</p>
                        <p class="text-xs text-slate-500 truncate">
                            @if($t->student_admission_number){{ $t->student_admission_number }} · @endif
                            {{ $t->subcategory_name ?? $t->category_name }}
                            @if($t->term_name) · {{ $t->term_name }}@endif
                        </p>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <p class="font-bold">{{ $money($t->fee_amount ?? $t->receiptBreakdown()['fee_subtotal']) }}</p>
                        <p class="text-xs text-slate-500">{{ ($t->paid_at ?? $t->created_at)?->format('d M, H:i') }}</p>
                    </div>
                </li>
            @empty
                <li class="px-5 py-6 text-sm text-slate-500">No payments yet. Share your payment link to start collecting.</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
