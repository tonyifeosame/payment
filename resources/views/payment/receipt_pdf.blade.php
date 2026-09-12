<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt {{ $transaction->reference }}</title>
    <style>
        /* Dompdf: plain CSS only, DejaVu Sans for the ₦ glyph. */
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0f172a; margin: 0; }
        .header { border-bottom: 3px solid #4f46e5; padding-bottom: 12px; margin-bottom: 16px; }
        .header table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: middle; }
        .logo { width: 64px; height: 64px; object-fit: contain; }
        .school { font-size: 20px; font-weight: bold; color: #1e1b4b; }
        .muted { color: #64748b; }
        .title { text-align: right; }
        .title .big { font-size: 16px; font-weight: bold; color: #4f46e5; text-transform: uppercase; letter-spacing: 1px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-weight: bold; font-size: 10px; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-other { background: #fee2e2; color: #991b1b; }
        .grid { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px 12px; }
        .grid td { width: 50%; vertical-align: top; }
        .box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; background: #f8fafc; }
        .box h3 { margin: 0 0 6px; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #475569; }
        .row { margin: 3px 0; }
        .row .k { color: #64748b; display: inline-block; width: 110px; }
        .row .v { font-weight: bold; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 10px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th { background: #eef2ff; color: #3730a3; text-align: left; padding: 7px 8px; font-size: 10px; text-transform: uppercase; border-bottom: 2px solid #c7d2fe; }
        table.items td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
        .r { text-align: right; }
        table.totals { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.totals td { padding: 4px 8px; }
        table.totals .label { text-align: right; color: #475569; }
        table.totals .grand td { font-size: 14px; font-weight: bold; color: #1e1b4b; border-top: 2px solid #4f46e5; padding-top: 8px; }
        .footer { margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 10px; font-size: 9.5px; color: #475569; }
        .notice { margin-top: 8px; white-space: pre-line; }
    </style>
</head>
<body>
@php
    $receipt = $transaction->receiptBreakdown();
    $paidAt = $transaction->paid_at ?? $transaction->created_at;
    $money = fn ($n) => '₦'.number_format((float) $n, 2);
@endphp

<div class="header">
    <table>
        <tr>
            <td style="width: 76px;">
                @if($logoDataUri)
                    <img class="logo" src="{{ $logoDataUri }}" alt="">
                @endif
            </td>
            <td>
                <div class="school">{{ $school?->name ?? 'Payment Receipt' }}</div>
                @if($school)
                    <div class="muted">{{ $school->address }}</div>
                    <div class="muted">{{ $school->phone }}{{ $school->phone && $school->email ? ' · ' : '' }}{{ $school->email }}</div>
                @endif
            </td>
            <td class="title">
                <div class="big">Official Receipt</div>
                <div class="muted">{{ $paidAt?->format('d M Y, h:i A') }}</div>
                <div style="margin-top: 4px;">
                    <span class="badge {{ $transaction->status === 'success' ? 'badge-success' : 'badge-other' }}">{{ strtoupper($transaction->status) }}</span>
                </div>
            </td>
        </tr>
    </table>
</div>

<table class="grid">
    <tr>
        <td>
            <div class="box">
                <h3>Student</h3>
                <div class="row"><span class="k">Name</span> <span class="v">{{ $transaction->student_name ?? '—' }}</span></div>
                <div class="row"><span class="k">Admission No.</span> <span class="v mono">{{ $transaction->student_admission_number ?? '—' }}</span></div>
                <div class="row"><span class="k">Class</span> <span class="v">{{ $transaction->student_class ?? '—' }}</span></div>
                <div class="row"><span class="k">Session / Term</span> <span class="v">{{ $transaction->session_name ?? '—' }}@if($transaction->term_name), {{ $transaction->term_name }}@endif</span></div>
            </div>
        </td>
        <td>
            <div class="box">
                <h3>Payment</h3>
                <div class="row"><span class="k">Reference</span> <span class="v mono">{{ $transaction->reference ?? '—' }}</span></div>
                <div class="row"><span class="k">Paid by</span> <span class="v">{{ $transaction->name ?? '—' }}</span></div>
                <div class="row"><span class="k">Email</span> <span class="v">{{ $transaction->email ?? '—' }}</span></div>
                <div class="row"><span class="k">Method</span> <span class="v">{{ ucfirst((string) ($transaction->payment_method ?? 'paystack')) }}</span></div>
            </div>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Category</th>
            <th>Fee</th>
            <th class="r">Unit Price</th>
            <th class="r">Qty</th>
            <th class="r">Amount</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $transaction->category_name ?? optional($transaction->category)->name }}</td>
            <td>{{ $transaction->subcategory_name ?? optional($transaction->subcategory)->name }}</td>
            <td class="r">{{ $money($receipt['unit_price']) }}</td>
            <td class="r">{{ $receipt['quantity'] }}</td>
            <td class="r">{{ $money($receipt['fee_subtotal']) }}</td>
        </tr>
    </tbody>
</table>

<table class="totals">
    @if($receipt['has_service_fee'])
        <tr><td class="label">Fee subtotal</td><td class="r" style="width: 140px;">{{ $money($receipt['fee_subtotal']) }}</td></tr>
        <tr><td class="label">Service fee</td><td class="r">{{ $money($receipt['service_fee']) }}</td></tr>
    @endif
    <tr class="grand"><td class="label">Total paid</td><td class="r">{{ $money($receipt['total']) }}</td></tr>
</table>

<div class="footer">
    <div>This receipt is official proof of payment for the transaction above. Keep it for your records and quote the reference in any enquiry to the school.</div>
    @if($school?->receipt_footer)
        <div class="notice">{{ $school->receipt_footer }}</div>
    @endif
    <div style="margin-top: 8px;" class="muted">Generated {{ now()->format('d M Y, h:i A') }} · computer-generated, no signature required.</div>
</div>
</body>
</html>
