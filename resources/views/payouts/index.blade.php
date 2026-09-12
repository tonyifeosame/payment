@extends('layouts.admin')

@section('title', 'Payouts')
@section('heading', 'Payout ledger')
@section('subheading', 'Every transfer of your share of a payment to your bank account.')

@section('content')
@php
    $money = fn ($n) => '₦'.number_format((float) $n, 2);
    $badge = fn ($status) => match($status) {
        'success' => 'bg-green-100 text-green-800',
        'pending', 'initiating', 'processing' => 'bg-amber-100 text-amber-800',
        'failed', 'needs_review' => 'bg-red-100 text-red-800',
        'reversed' => 'bg-slate-200 text-slate-800',
        default => 'bg-slate-100 text-slate-700',
    };
@endphp

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="card p-5"><p class="text-xs font-black uppercase tracking-wider text-slate-500">Paid to bank</p><p class="text-2xl font-black text-green-700 mt-2">{{ $money($summary['paid']['amount']) }}</p><p class="text-xs text-slate-500">{{ $summary['paid']['count'] }} {{ Str::plural('transfer', $summary['paid']['count']) }}</p></div>
    <div class="card p-5"><p class="text-xs font-black uppercase tracking-wider text-slate-500">On the way</p><p class="text-2xl font-black text-amber-600 mt-2">{{ $money($summary['in_progress']['amount']) }}</p><p class="text-xs text-slate-500">queued, sending or processing at the bank</p></div>
    <div class="card p-5"><p class="text-xs font-black uppercase tracking-wider text-slate-500">Needs attention</p><p class="text-2xl font-black text-red-600 mt-2">{{ $money($summary['attention']['amount']) }}</p><p class="text-xs text-slate-500">failed or under review — contact support with the reference</p></div>
</div>

<form method="GET" class="card p-4 mb-4 flex flex-wrap gap-3 items-end">
    <div>
        <label for="status" class="label">Status</label>
        <select id="status" name="status" class="input" onchange="this.form.submit()">
            <option value="">All</option>
            @foreach($labels as $value => $label)
                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <a class="btn-secondary" href="{{ route('school.payouts.index', ['school' => $school->slug]) }}">Reset</a>
    <p class="text-xs text-slate-500 ml-auto max-w-md">Amounts here are your share of each payment. The service fee charged to the parent is never transferred to the school.</p>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="th">Date</th>
                    <th class="th">Payment</th>
                    <th class="th text-right">Amount</th>
                    <th class="th">Status</th>
                    <th class="th">Payout reference</th>
                    <th class="th">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse($payouts as $payout)
                    <tr>
                        <td class="td whitespace-nowrap">
                            {{ ($payout->completed_at ?? $payout->initiated_at ?? $payout->created_at)?->format('d M Y') }}
                            <span class="block text-xs text-slate-500">{{ ($payout->completed_at ?? $payout->initiated_at ?? $payout->created_at)?->format('H:i') }}</span>
                        </td>
                        <td class="td">
                            @if($payout->transaction)
                                <span class="font-semibold">{{ $payout->transaction->student_name ?? $payout->transaction->name ?? $payout->transaction->email }}</span>
                                <span class="block text-xs text-slate-500">{{ $payout->transaction->subcategory_name ?? $payout->transaction->category_name }}</span>
                                <span class="block text-xs font-mono text-slate-500 break-all">{{ $payout->transaction->reference }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="td text-right font-bold whitespace-nowrap">{{ $money($payout->amount) }}</td>
                        <td class="td">
                            <span class="badge {{ $badge($payout->status) }}">{{ $labels[$payout->status] ?? ucfirst($payout->status) }}</span>
                            @if($payout->attempts > 1)<span class="block text-xs text-slate-500 mt-1">{{ $payout->attempts }} attempts</span>@endif
                        </td>
                        <td class="td font-mono text-xs break-all">
                            {{ $payout->reference ?? '—' }}
                            @if($payout->transfer_code)<span class="block text-slate-500">{{ $payout->transfer_code }}</span>@endif
                        </td>
                        <td class="td text-xs text-slate-600 max-w-xs">
                            @if(in_array($payout->status, ['failed', 'needs_review'], true) && $payout->last_error)
                                {{ $payout->last_error }}
                            @elseif($payout->status === 'success')
                                Paid {{ $payout->completed_at?->format('d M Y, H:i') }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500">No payouts yet. A payout is created the moment a payment is confirmed.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($payouts->hasPages())
        <div class="px-4 py-3 border-t border-slate-100">{{ $payouts->links() }}</div>
    @endif
</div>
@endsection
