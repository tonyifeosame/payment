<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt {{ $transaction->reference }}</title>
    <style>
        /* Dompdf: plain CSS only, table layout, DejaVu Sans for the ₦ glyph (the web
           fonts are not available to the PDF renderer). Palette mirrors head-tokens:
           Royal Violet #5423E7, Obsidian #121217, Fog #F7F7F8, Slate #6C6C89, Ash #D1D1DB. */
        @page { margin: 36px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; line-height: 1.45; color: #121217; margin: 0; }
        table { border-collapse: collapse; }
        td { vertical-align: top; }
        .muted { color: #6C6C89; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 9.5px; }
        .r { text-align: right; }
        .b { font-weight: bold; }

        /* Header: school identity left, FEYRA right, violet rule beneath. */
        .header { width: 100%; }
        .header td { padding-bottom: 12px; vertical-align: middle; }
        .rule { border-top: 3px solid #5423E7; margin-bottom: 16px; }
        .logo { width: 52px; height: 52px; }
        .logo-tile { width: 52px; height: 52px; border-radius: 12px; background: #5423E7; }
        .logo-tile table { width: 52px; height: 52px; }
        .logo-tile td { color: #fff; font-size: 22px; font-weight: bold; text-align: center; vertical-align: middle; padding: 0; }
        .school-name { font-size: 17px; font-weight: bold; line-height: 1.2; }
        .brand { font-size: 13px; font-weight: bold; }
        .brand img { width: 20px; height: 20px; vertical-align: middle; margin-right: 5px; }
        .doc-title { font-size: 9px; text-transform: uppercase; letter-spacing: 1.2px; color: #6C6C89; margin-top: 4px; }

        /* Summary band: outcome + the total, the strongest element on the page. */
        .summary { width: 100%; background: #F7F7F8; border-radius: 12px; margin-bottom: 16px; }
        .summary td { padding: 14px 16px; vertical-align: middle; }
        .summary .headline { font-size: 15px; font-weight: bold; }
        .summary .sub { color: #6C6C89; margin-top: 2px; }
        .summary .total-label { font-size: 9px; text-transform: uppercase; letter-spacing: 1.2px; color: #6C6C89; }
        .summary .total { font-size: 24px; font-weight: bold; line-height: 1.1; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 8.5px; font-weight: bold; }
        .badge-success { background: #DCFCE7; color: #166534; }
        .badge-other { background: #E9E9EE; color: #6C6C89; }

        /* Two-column detail sections. */
        .cols { width: 100%; margin-bottom: 14px; }
        .cols > tbody > tr > td { width: 50%; padding: 0; }
        .cols > tbody > tr > td.gap { width: 14px; }
        .section { border: 1px solid #D1D1DB; border-radius: 12px; padding: 10px 14px 6px; }
        .section h3 { margin: 0 0 4px; font-size: 8.5px; text-transform: uppercase; letter-spacing: 1.2px; color: #6C6C89; font-weight: bold; }
        .kv { width: 100%; }
        .kv td { padding: 4px 0; border-top: 1px solid #F0F0F3; }
        .kv tr:first-child td { border-top: 0; }
        .kv .k { color: #6C6C89; width: 38%; }
        .kv .v { font-weight: bold; text-align: right; word-wrap: break-word; }

        /* Fee lines + totals. */
        .fee { width: 100%; border: 1px solid #D1D1DB; border-radius: 12px; padding: 10px 14px 4px; margin-bottom: 0; }
        .fee h3 { margin: 0 0 6px; font-size: 8.5px; text-transform: uppercase; letter-spacing: 1.2px; color: #6C6C89; font-weight: bold; }
        table.items { width: 100%; }
        table.items th { background: #F7F7F8; color: #6C6C89; text-align: left; padding: 6px 8px; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: bold; }
        table.items td { padding: 8px; border-bottom: 1px solid #F0F0F3; }
        table.totals { width: 100%; margin-top: 4px; }
        table.totals td { padding: 4px 8px; }
        table.totals .label { text-align: right; color: #6C6C89; }
        table.totals .amount { text-align: right; width: 130px; font-weight: bold; }
        .grand { width: 100%; margin-top: 8px; background: #F7F7F8; border-radius: 10px; }
        .grand td { padding: 10px 12px; vertical-align: middle; }
        .grand .label { font-size: 11px; font-weight: bold; }
        .grand .amount { font-size: 18px; font-weight: bold; text-align: right; }

        .footer { margin-top: 18px; border-top: 1px solid #D1D1DB; padding-top: 10px; font-size: 8.5px; color: #6C6C89; }
        .notice { margin-top: 6px; white-space: pre-line; color: #121217; }
        .footer .brand-line { margin-top: 8px; }
        .footer .brand-line img { width: 12px; height: 12px; vertical-align: middle; margin-right: 3px; }
    </style>
</head>
<body>
@php
    // Same source of truth as the web receipt; nothing is recalculated here.
    $receipt = $transaction->receiptBreakdown();
    $paidAt = \App\Support\BusinessTime::display($transaction->paid_at ?? $transaction->created_at);
    $isSuccess = $transaction->status === 'success';
    $money = fn ($n) => '₦'.number_format((float) $n, 2);
    $method = $transaction->payment_method
        ? ucfirst(str_replace('_', ' ', (string) $transaction->payment_method))
        : null;
    $categoryName = $transaction->category_name ?? optional($transaction->category)->name;
    $feeName = $transaction->subcategory_name ?? optional($transaction->subcategory)->name;

    // The shared brand mark, embedded as a data URI so Dompdf never fetches anything.
    // The partial omits xmlns (fine inline in HTML); a standalone SVG document needs it.
    $brandMark = 'data:image/svg+xml;base64,'.base64_encode(
        str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', view('marketing.partials.logo-mark', ['size' => 34])->render())
    );
    $brandName = trim(view('marketing.partials.brand-name')->render());
@endphp

<table class="header">
    <tr>
        @if($logoDataUri)
            <td style="width: 64px;"><img class="logo" src="{{ $logoDataUri }}" alt=""></td>
        @elseif($school)
            <td style="width: 64px;"><div class="logo-tile"><table><tr><td>{{ mb_substr($school->name, 0, 1) }}</td></tr></table></div></td>
        @endif
        <td>
            <div class="school-name">{{ $school?->name ?? 'Payment receipt' }}</div>
            @if($school?->address)<div class="muted">{{ $school->address }}</div>@endif
            @if($school?->phone || $school?->email)
                <div class="muted">{{ $school->phone }}{{ $school->phone && $school->email ? ' · ' : '' }}{{ $school->email }}</div>
            @endif
        </td>
        <td class="r" style="width: 150px;">
            <div class="brand"><img src="{{ $brandMark }}" alt="">{{ $brandName }}</div>
            <div class="doc-title">Official receipt</div>
            <div class="muted">{{ $paidAt?->format('d M Y, h:i A') }}</div>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table class="summary">
    <tr>
        <td>
            <div class="headline">{{ $isSuccess ? 'Payment successful' : 'Payment '.strtolower($transaction->status) }}</div>
            <div class="sub">
                @if($isSuccess)
                    Thank you{{ $transaction->name ? ', '.$transaction->name : '' }}. Your payment{{ $school ? ' to '.$school->name : '' }} has been received.
                @else
                    This payment has not been confirmed as successful.
                @endif
            </div>
        </td>
        <td class="r" style="width: 170px;">
            <div class="total-label">Total paid</div>
            <div class="total">{{ $money($receipt['total']) }}</div>
        </td>
    </tr>
</table>

<table class="cols">
    <tr>
        <td>
            <div class="section">
                <h3>Payment</h3>
                <table class="kv">
                    <tr><td class="k">Reference</td><td class="v mono">{{ $transaction->reference ?? '—' }}</td></tr>
                    <tr><td class="k">Date</td><td class="v">{{ $paidAt ? $paidAt->format('d M Y, h:i A').' '.\App\Support\BusinessTime::label() : '—' }}</td></tr>
                    @if($method)
                        <tr><td class="k">Method</td><td class="v">{{ $method }}</td></tr>
                    @endif
                    <tr><td class="k">Status</td><td class="v"><span class="badge {{ $isSuccess ? 'badge-success' : 'badge-other' }}">{{ $isSuccess ? 'Successful' : ucfirst($transaction->status) }}</span></td></tr>
                    @if($transaction->name)
                        <tr><td class="k">Paid by</td><td class="v">{{ $transaction->name }}</td></tr>
                    @endif
                    @if($transaction->email)
                        <tr><td class="k">Email</td><td class="v" style="font-weight: normal;">{{ $transaction->email }}</td></tr>
                    @endif
                </table>
            </div>
        </td>
        <td class="gap"></td>
        <td>
            <div class="section">
                <h3>Student</h3>
                <table class="kv">
                    @if($transaction->hasStudent())
                        <tr><td class="k">Name</td><td class="v">{{ $transaction->student_name ?? '—' }}</td></tr>
                        @if($transaction->student_class)
                            <tr><td class="k">Class</td><td class="v">{{ $transaction->student_class }}</td></tr>
                        @endif
                        @if($transaction->student_admission_number)
                            <tr><td class="k">Admission No.</td><td class="v mono">{{ $transaction->student_admission_number }}</td></tr>
                        @endif
                    @else
                        <tr><td class="k">Name</td><td class="v">—</td></tr>
                    @endif
                    <tr><td class="k">Session / Term</td><td class="v">{{ $transaction->session_name ?? '—' }}@if($transaction->term_name), {{ $transaction->term_name }}@endif</td></tr>
                </table>
            </div>
        </td>
    </tr>
</table>

<div class="fee">
    <h3>Fee</h3>
    <table class="items">
        <thead>
            <tr>
                <th>Category</th>
                <th>Fee type</th>
                <th class="r">Unit price</th>
                <th class="r">Qty</th>
                <th class="r">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $categoryName ?? '—' }}</td>
                <td>{{ $feeName ?? '—' }}</td>
                <td class="r">{{ $money($receipt['unit_price']) }}</td>
                <td class="r">{{ $receipt['quantity'] }}</td>
                <td class="r b">{{ $money($receipt['fee_subtotal']) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="label">Fee subtotal</td><td class="amount">{{ $money($receipt['fee_subtotal']) }}</td></tr>
        @if($receipt['has_service_fee'])
            <tr><td class="label">Service fee</td><td class="amount">{{ $money($receipt['service_fee']) }}</td></tr>
        @endif
    </table>

    <table class="grand">
        <tr>
            <td class="label">Total paid</td>
            <td class="amount">{{ $money($receipt['total']) }}</td>
        </tr>
    </table>
</div>

<div class="footer">
    <div>This receipt is official proof of payment for the transaction above. Keep it for your records and quote the reference in any enquiry to the school.</div>
    @if($school?->receipt_footer)
        <div class="notice">{{ $school->receipt_footer }}</div>
    @endif
    <div class="brand-line"><img src="{{ $brandMark }}" alt="">{{ $brandName }} · Generated {{ now()->format('d M Y, h:i A') }} · computer-generated, no signature required.</div>
</div>
</body>
</html>
