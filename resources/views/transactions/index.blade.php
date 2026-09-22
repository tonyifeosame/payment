@extends('layouts.admin')

@section('title', 'Transactions')
@section('eyebrow', 'Payments')
@section('heading', 'Transactions')
@section('subheading', 'Every payment attempt on your payment page. Only successful payments count as collections.')
@section('actions')
    {{-- Exports exactly the rows the current filters show: the query string is passed through unchanged. --}}
    <a href="{{ route('school.transactions.export', array_merge(['school' => $school->slug], request()->query())) }}" class="btn-obsidian">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14"/></svg>
        Export CSV
    </a>
@endsection

@section('content')
@php
    // Which filters are narrowing the list beyond the default "successful payments"
    // view. Purely presentational: mirrors what Transaction::scopeFilter will honour
    // (unknown ids and malformed dates are ignored there, so they are not shown as
    // active here either). Each entry is [label, value].
    $indexUrl = fn (array $except = []) => route('school.transactions.index', array_merge(['school' => $school->slug], Arr::except(request()->query(), array_merge($except, ['page']))));
    $pick = fn ($rows, $value) => ctype_digit((string) $value) ? $rows->firstWhere('id', (int) $value) : null;
    $formatDate = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))
        ? rescue(fn () => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', trim($value))->format('d M Y'), null, false)
        : null;
    $activeFilters = [];
    if ($filters['q'] !== '') {
        $activeFilters['q'] = ['Search', '“'.$filters['q'].'”'];
    }
    if ($filters['status'] !== \App\Models\Transaction::STATUS_SUCCESS) {
        $activeFilters['status'] = ['Status', in_array($filters['status'], $statuses, true) ? ucfirst($filters['status']) : 'All statuses'];
    }
    if ($category = $pick($categories, $filters['category_id'])) {
        $activeFilters['category_id'] = ['Category', $category->name];
    }
    if ($session = $pick($sessions, $filters['session_id'])) {
        $activeFilters['session_id'] = ['Session', $session->name];
    }
    if ($term = $pick($terms, $filters['term_id'])) {
        $activeFilters['term_id'] = ['Term', $term->name.', '.$term->session->name];
    }
    if ($from = $formatDate($filters['date_from'])) {
        $activeFilters['date_from'] = ['From', $from];
    }
    if ($to = $formatDate($filters['date_to'])) {
        $activeFilters['date_to'] = ['To', $to];
    }
@endphp

{{-- Filters --}}
<form method="GET" action="{{ route('school.transactions.index', ['school' => $school->slug]) }}" class="card p-5 sm:p-6" role="search" aria-labelledby="filters-heading">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="filters-heading" class="font-display text-lg font-bold tracking-tight">Filter transactions</h2>
        <p class="text-sm text-brand-slate">Showing successful payments unless you choose another status.</p>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-12">
        <div class="sm:col-span-2 lg:col-span-6">
            <label for="q" class="label">Search</label>
            <input id="q" name="q" type="search" value="{{ $q }}" class="input" placeholder="Student, admission no., payer, email or reference" autocomplete="off" enterkeyhint="search">
        </div>
        <div class="lg:col-span-3">
            <label for="status" class="label">Status</label>
            <select id="status" name="status" class="input">
                <option value="all" @selected($filters['status'] === 'all')>All statuses</option>
                @foreach($statuses as $s)
                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:col-span-3">
            <label for="category_id" class="label">Category</label>
            <select id="category_id" name="category_id" class="input">
                <option value="">All categories</option>
                @foreach($categories as $c)
                    <option value="{{ $c->id }}" @selected((string) $filters['category_id'] === (string) $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:col-span-3">
            <label for="session_id" class="label">Session</label>
            <select id="session_id" name="session_id" class="input">
                <option value="">All sessions</option>
                @foreach($sessions as $s)
                    <option value="{{ $s->id }}" @selected((string) $filters['session_id'] === (string) $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:col-span-3">
            <label for="term_id" class="label">Term</label>
            <select id="term_id" name="term_id" class="input">
                <option value="">All terms</option>
                @foreach($terms as $t)
                    <option value="{{ $t->id }}" @selected((string) $filters['term_id'] === (string) $t->id)>{{ $t->name }}, {{ $t->session->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:col-span-3">
            <label for="date_from" class="label">From</label>
            <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input">
        </div>
        <div class="lg:col-span-3">
            <label for="date_to" class="label">To</label>
            <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input">
        </div>
        <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:justify-end lg:col-span-12">
            <a class="btn-outline sm:w-auto" href="{{ route('school.transactions.index', ['school' => $school->slug]) }}">Reset filters</a>
            <button class="btn-obsidian sm:w-auto" type="submit">Apply filters</button>
        </div>
    </div>

    @if($activeFilters)
        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-brand-ash/60 pt-5" role="group" aria-label="Active filters">
            <span class="mr-1 text-xs font-semibold uppercase tracking-[0.08em] text-brand-violet">{{ count($activeFilters) }} {{ Str::plural('filter', count($activeFilters)) }} active</span>
            {{-- Loop variables are prefixed: Blade @include shares this scope, and a bare
                 $label here would leak into admin._badge's optional $label below. --}}
            @foreach($activeFilters as $chipKey => [$chipLabel, $chipValue])
                <a href="{{ $indexUrl([$chipKey]) }}"
                   class="inline-flex min-h-[48px] items-center gap-1.5 rounded-full border border-brand-violet/30 bg-brand-violet/10 py-1 pl-4 pr-3 text-sm font-medium text-brand-violet hover:border-brand-violet hover:bg-brand-violet/15 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">
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
        <span class="font-display text-lg font-bold text-brand-obsidian">{{ number_format($transactions->total()) }}</span>
        @if($activeFilters) {{ Str::plural('transaction', $transactions->total()) }} match your filters @else successful {{ Str::plural('transaction', $transactions->total()) }} @endif
    </p>
    @if($transactions->total() > 0 && $transactions->hasPages())
        <p class="text-sm text-brand-slate">Page {{ $transactions->currentPage() }} of {{ $transactions->lastPage() }}</p>
    @endif
</div>

{{-- List: a table from md up, stacked cards below. From xl the layout is fixed with
     percentage columns (they scale up when Payer is hidden) so long references or
     emails wrap inside their cell instead of squeezing the others; below xl the
     browser balances six columns itself. Payer only fits alongside the
     other six from ~1400px (1280 minus the sidebar is too tight for seven), so it is
     hidden below that on desktop — it is always shown in the mobile cards. --}}
@php
    $columns = [['Date', 'left', 'xl:w-[14%]'], ['Student', 'left', 'xl:w-[15%]'], ['Fee', 'left', 'xl:w-[16%]'], ['Payer', 'left', 'hidden min-[1400px]:table-cell min-[1400px]:w-[15%]'], ['Amount', 'right', 'xl:w-[13%]'], ['Status', 'left', 'xl:w-[10%]'], ['Actions', 'actions', 'xl:w-[17%]']];
@endphp
<x-admin.table
    :columns="$transactions->isEmpty() ? [] : $columns"
    class="xl:[&_table]:w-full xl:[&_table]:table-fixed md:[&_.th]:px-3 md:[&_.td]:px-3"
    caption="Transactions for {{ $school->name }}, newest first"
    stacked>
    @forelse($transactions as $t)
        @php $b = $t->receiptBreakdown(); $when = \App\Support\BusinessTime::display($t->paid_at ?? $t->created_at); @endphp
        <tr>
            <td class="td" data-label="Date">
                <div class="min-w-0">
                <time datetime="{{ $when?->toIso8601String() }}" class="whitespace-nowrap font-medium">{{ $when?->format('d M Y') }}</time>
                <span class="block text-xs text-brand-slate">{{ $when?->format('H:i') }}</span>
                <span class="block break-all font-mono text-[11px] leading-4 text-brand-slate">{{ $t->reference }}</span>
                </div>
            </td>
            <td class="td" data-label="Student">
                <div class="min-w-0">
                @if($t->hasStudent())
                    @if($t->student_id)
                        <a class="rounded font-semibold text-brand-obsidian hover:text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30" href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $t->student_id]) }}">{{ $t->student_name }}</a>
                    @else
                        <span class="font-semibold">{{ $t->student_name }}</span>
                    @endif
                    <span class="block text-xs text-brand-slate"><span class="font-mono">{{ $t->student_admission_number }}</span>@if($t->student_class) · {{ $t->student_class }}@endif</span>
                @else
                    <span class="text-brand-slate">No student</span>
                @endif
                </div>
            </td>
            <td class="td" data-label="Fee">
                <div class="min-w-0">
                <span class="font-medium">{{ $t->subcategory_name ?? '—' }}</span>
                <span class="block text-xs text-brand-slate">{{ $t->category_name }}@if($b['quantity'] > 1) × {{ $b['quantity'] }}@endif @if($t->term_name)· {{ $t->term_name }}, {{ $t->session_name }}@endif</span>
                </div>
            </td>
            <td class="td hidden max-md:flex min-[1400px]:table-cell" data-label="Payer">
                <div class="min-w-0">
                <span class="block">{{ $t->name ?? '—' }}</span>
                <span class="block break-words text-xs text-brand-slate">{{ $t->email }}</span>
                </div>
            </td>
            <td class="td text-right lg:whitespace-nowrap" data-label="Amount">
                <div class="min-w-0">
                <span class="whitespace-nowrap font-display text-base font-bold tabular-nums">₦{{ number_format($b['fee_subtotal'], 2) }}</span>
                @if($b['quantity'] > 1)<span class="block text-xs text-brand-slate">{{ $b['quantity'] }} × ₦{{ number_format($b['unit_price'], 2) }}</span>@endif
                </div>
            </td>
            <td class="td" data-label="Status">@include('admin._badge', ['status' => $t->status])</td>
            <td class="td td-actions" data-label="">
                {{-- Only payments that are (or may still become) collections get a detail
                     action; failed/mismatched attempts are listed as data, nothing more. --}}
                @if(in_array($t->status, ['success', 'pending'], true))
                    <div class="flex w-full gap-2 md:w-auto md:justify-end">
                        @if($t->status === 'success')
                            <a class="btn-outline btn-sm !min-h-[48px] flex-1 !px-4 text-sm md:flex-none" href="{{ route('payment.receipt', $t->id) }}">Receipt<span class="sr-only"> for {{ $t->reference }}</span></a>
                        @endif
                        <a class="btn-outline btn-sm !min-h-[48px] flex-1 !px-4 text-sm md:flex-none" href="{{ route('school.transactions.show', ['school' => $school->slug, 'transaction' => $t->id]) }}">View<span class="sr-only"> transaction {{ $t->reference }}</span></a>
                    </div>
                @else
                    <span class="sr-only">No actions for {{ $t->reference }}</span>
                @endif
            </td>
        </tr>
    @empty
        <x-slot:empty>
            @if($activeFilters)
                <x-admin.empty
                    title="No transactions match these filters"
                    description="Try a wider date range, another status or a shorter search term."
                    icon="M21 21l-4.3-4.3M11 18a7 7 0 100-14 7 7 0 000 14z">
                    <a class="btn-outline" href="{{ route('school.transactions.index', ['school' => $school->slug]) }}">Reset filters</a>
                </x-admin.empty>
            @else
                <x-admin.empty
                    title="No transactions yet"
                    description="Successful payments appear here as soon as parents pay on your payment page."
                    icon="M4 8h16M4 16h16M8 4l-4 4 4 4M16 12l4 4-4 4">
                    <a class="btn-outline" href="{{ route('school.transactions.index', ['school' => $school->slug, 'status' => 'all']) }}">Show every status</a>
                </x-admin.empty>
            @endif
        </x-slot:empty>
    @endforelse
    @if($transactions->hasPages())
        <x-slot:footer>{{ $transactions->links() }}</x-slot:footer>
    @endif
</x-admin.table>
@endsection
