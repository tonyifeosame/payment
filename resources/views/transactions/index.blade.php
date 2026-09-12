@extends('layouts.admin')

@section('title', 'Transactions')
@section('heading', 'Transactions')
@section('subheading', 'Every payment attempt on your payment page. Only successful payments count as collections.')
@section('actions')
    <a href="{{ route('school.transactions.export', array_merge(['school' => $school->slug], request()->query())) }}" class="btn-primary">⬇ Export CSV</a>
@endsection

@section('content')
<form method="GET" class="card p-4 mb-4">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div class="md:col-span-2">
            <label for="q" class="label">Search</label>
            <input id="q" name="q" value="{{ $q }}" class="input" placeholder="Student, admission no., payer name, email or reference">
        </div>
        <div>
            <label for="status" class="label">Status</label>
            <select id="status" name="status" class="input">
                <option value="all" @selected($filters['status'] === 'all')>All statuses</option>
                @foreach($statuses as $s)
                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="category_id" class="label">Category</label>
            <select id="category_id" name="category_id" class="input">
                <option value="">All categories</option>
                @foreach($categories as $c)
                    <option value="{{ $c->id }}" @selected((string) $filters['category_id'] === (string) $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="session_id" class="label">Session</label>
            <select id="session_id" name="session_id" class="input">
                <option value="">All sessions</option>
                @foreach($sessions as $s)
                    <option value="{{ $s->id }}" @selected((string) $filters['session_id'] === (string) $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="term_id" class="label">Term</label>
            <select id="term_id" name="term_id" class="input">
                <option value="">All terms</option>
                @foreach($terms as $t)
                    <option value="{{ $t->id }}" @selected((string) $filters['term_id'] === (string) $t->id)>{{ $t->name }}, {{ $t->session->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="date_from" class="label">From</label>
            <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] }}" class="input">
        </div>
        <div>
            <label for="date_to" class="label">To</label>
            <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] }}" class="input">
        </div>
    </div>
    <div class="flex gap-2 mt-4">
        <button class="btn-primary" type="submit">Apply filters</button>
        <a class="btn-secondary" href="{{ route('school.transactions.index', ['school' => $school->slug]) }}">Reset</a>
        <span class="self-center text-sm text-slate-500 ml-auto">{{ number_format($transactions->total()) }} {{ Str::plural('result', $transactions->total()) }}</span>
    </div>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="th">Date</th>
                    <th class="th">Student</th>
                    <th class="th">Fee</th>
                    <th class="th">Term</th>
                    <th class="th">Payer</th>
                    <th class="th text-right">Fee amount</th>
                    <th class="th text-right">Charged</th>
                    <th class="th">Status</th>
                    <th class="th">Reference</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse($transactions as $t)
                    @php $b = $t->receiptBreakdown(); @endphp
                    <tr class="hover:bg-blue-50/50">
                        <td class="td whitespace-nowrap">
                            {{ ($t->paid_at ?? $t->created_at)?->format('d M Y') }}
                            <span class="block text-xs text-slate-500">{{ ($t->paid_at ?? $t->created_at)?->format('H:i') }}</span>
                        </td>
                        <td class="td">
                            @if($t->hasStudent())
                                @if($t->student_id)
                                    <a class="font-semibold hover:underline" href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $t->student_id]) }}">{{ $t->student_name }}</a>
                                @else
                                    <span class="font-semibold">{{ $t->student_name }}</span>
                                @endif
                                <span class="block text-xs text-slate-500 font-mono">{{ $t->student_admission_number }} @if($t->student_class)· {{ $t->student_class }}@endif</span>
                            @else
                                <span class="text-slate-400">No student</span>
                            @endif
                        </td>
                        <td class="td">
                            {{ $t->subcategory_name ?? '—' }}
                            <span class="block text-xs text-slate-500">{{ $t->category_name }} @if($b['quantity'] > 1)× {{ $b['quantity'] }}@endif</span>
                        </td>
                        <td class="td whitespace-nowrap">{{ $t->term_name ? $t->term_name.', '.$t->session_name : '—' }}</td>
                        <td class="td">
                            {{ $t->name ?? '—' }}
                            <span class="block text-xs text-slate-500">{{ $t->email }}</span>
                        </td>
                        <td class="td text-right font-bold whitespace-nowrap">₦{{ number_format($b['fee_subtotal'], 2) }}</td>
                        <td class="td text-right whitespace-nowrap text-slate-600">₦{{ number_format($b['total'], 2) }}</td>
                        <td class="td">@include('transactions._status', ['status' => $t->status])</td>
                        <td class="td">
                            <span class="font-mono text-xs break-all">{{ $t->reference }}</span>
                            @if($t->status === 'success')
                                <a class="block text-xs text-blue-700 underline mt-1" href="{{ route('payment.receipt', $t->id) }}">Receipt</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center text-slate-500">
                            <p class="font-bold text-slate-800">No transactions found</p>
                            <p class="text-sm">Try adjusting your search criteria.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transactions->hasPages())
        <div class="px-4 py-3 border-t border-slate-100">{{ $transactions->links() }}</div>
    @endif
</div>
@endsection
