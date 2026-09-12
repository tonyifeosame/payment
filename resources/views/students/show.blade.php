@extends('layouts.admin')

@section('title', $student->full_name)
@section('heading', $student->full_name)
@section('subheading', $student->admission_number.' · '.$student->class_name.($student->session ? ' · '.$student->session->name : ''))
@section('actions')
    <a href="{{ route('school.students.edit', ['school' => $school->slug, 'student' => $student->id]) }}" class="btn-secondary">Edit</a>
    <a href="{{ route('school.students.index', ['school' => $school->slug]) }}" class="btn-secondary">All students</a>
@endsection

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="card p-5">
        <p class="text-xs font-black uppercase tracking-wider text-slate-500">Total fees paid</p>
        <p class="text-3xl font-black text-green-700 mt-2">₦{{ number_format($totalPaid, 2) }}</p>
        <p class="text-xs text-slate-500 mt-1">school share of successful payments, all time</p>
        <dl class="mt-5 text-sm space-y-2">
            <div><dt class="text-slate-500">Guardian</dt><dd class="font-semibold">{{ $student->guardian_name ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Phone</dt><dd class="font-semibold">{{ $student->guardian_phone ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Email</dt><dd class="font-semibold">{{ $student->guardian_email ?? '—' }}</dd></div>
        </dl>
    </div>

    <div class="card overflow-hidden lg:col-span-2">
        <div class="px-5 py-4 border-b border-slate-100"><h2 class="font-bold text-slate-900">Payment history</h2></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50"><tr>
                    <th class="th">Date</th><th class="th">Fee</th><th class="th">Term</th><th class="th">Status</th><th class="th text-right">Fee amount</th><th class="th"></th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @forelse($transactions as $t)
                        <tr>
                            <td class="td whitespace-nowrap">{{ ($t->paid_at ?? $t->created_at)?->format('d M Y, H:i') }}</td>
                            <td class="td">{{ $t->subcategory_name }}<span class="block text-xs text-slate-500">{{ $t->category_name }}</span></td>
                            <td class="td">{{ $t->term_name ? $t->term_name.', '.$t->session_name : '—' }}</td>
                            <td class="td">@include('transactions._status', ['status' => $t->status])</td>
                            <td class="td text-right font-bold">₦{{ number_format($t->receiptBreakdown()['fee_subtotal'], 2) }}</td>
                            <td class="td text-right">
                                @if($t->status === 'success')
                                    <a class="text-blue-700 underline text-xs" href="{{ route('payment.receipt', $t->id) }}">Receipt</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No payments recorded for this student yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
