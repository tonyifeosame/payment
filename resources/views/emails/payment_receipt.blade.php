{{-- Built on mail::layout rather than mail::message so the header and footer can carry
     the FEYRA brand instead of the app name; the body is still parsed as Markdown and the
     theme CSS is still inlined, exactly as before. --}}
@component('mail::layout')
@slot('head')
<style>
.wrapper, .body { background-color: #F7F7F8 !important; }
</style>
@endslot
@slot('header')
@component('mail::header', ['url' => config('app.url')])
<img src="{{ asset('images/feyra-mark.png') }}" width="28" height="28" alt="" style="width: 28px; height: 28px; border-radius: 7px; vertical-align: middle;"> <span style="font-size: 19px; font-weight: 700; color: #121217; vertical-align: middle;">@include('marketing.partials.brand-name')</span>
@endcomponent
@endslot
@php
    // The transaction's own school_id is the authoritative source. This used to be
    // derived through category->school and subcategory->school, which made the
    // school's name on an ALREADY-ISSUED receipt depend on rows that outlive it:
    // transactions.category_id and subcategory_id are nullOnDelete, so deleting a
    // fee type silently stripped the school's branding from historical receipts,
    // and a category repointed to another school would have printed that school's
    // name on this school's receipt.
    $school = $transaction->school;
    $schoolName = $school?->name;

    // Same source of truth as the web receipt and the PDF; nothing is recalculated.
    $receipt = $transaction->receiptBreakdown();
    $paidAt = $transaction->paid_at ?? $transaction->created_at;
    $isSuccess = $transaction->status === 'success';
    $money = fn ($n) => '₦'.number_format((float) $n, 2);
    $method = $transaction->payment_method
        ? ucfirst(str_replace('_', ' ', (string) $transaction->payment_method))
        : null;
    $categoryName = $transaction->category_name ?? optional($transaction->category)->name;
    $feeName = $transaction->subcategory_name ?? optional($transaction->subcategory)->name;

    // Email-client-safe inline styles (Gmail strips <style> and ignores classes).
    // Blank lines are avoided inside the HTML below: this file is parsed as
    // Markdown, and a blank line ends a raw-HTML block.
    $font = "font-family: 'Plus Jakarta Sans', Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
    $label = 'padding: 7px 0; font-size: 14px; line-height: 1.4; color: #6C6C89; vertical-align: top; white-space: nowrap; '.$font;
    $value = 'padding: 7px 0 7px 12px; font-size: 14px; line-height: 1.4; font-weight: 600; color: #121217; text-align: right; vertical-align: top; word-break: break-word; '.$font;
    $sectionTitle = 'padding: 16px 0 4px; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; font-weight: 700; color: #6C6C89; '.$font;
@endphp
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse;">
<tr>
<td align="center" style="padding: 4px 0 16px;">
<table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: separate;">
<tr>
<td align="center" valign="middle" width="56" height="56" style="width: 56px; height: 56px; border-radius: 28px; background-color: {{ $isSuccess ? '#16A34A' : '#D1D1DB' }}; color: #ffffff; font-size: 28px; font-weight: 700; line-height: 56px; {{ $font }}">{{ $isSuccess ? '✓' : '·' }}</td>
</tr>
</table>
</td>
</tr>
<tr>
<td align="center" style="padding-bottom: 6px; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; font-weight: 700; color: #6C6C89; {{ $font }}">Official Payment Receipt</td>
</tr>
<tr>
<td align="center">
<h1 style="margin: 0; font-size: 26px; line-height: 1.2; font-weight: 800; letter-spacing: -0.3px; color: #121217; text-align: center; {{ $font }}">{{ $isSuccess ? 'Payment successful' : 'Payment '.strtolower($transaction->status) }}</h1>
</td>
</tr>
<tr>
<td align="center" style="padding: 10px 0 0; font-size: 15px; line-height: 1.5; color: #6C6C89; text-align: center; {{ $font }}">
@if($isSuccess)
Thank you{{ $transaction->name ? ', '.$transaction->name : '' }}. Your payment of <strong style="color: #121217;">{{ $money($receipt['total']) }}</strong>{{ $schoolName ? ' to '.$schoolName : '' }} has been received.
@else
This payment has not been confirmed as successful. The details recorded for it are below.
@endif
</td>
</tr>
</table>
@isset($receiptUrl)
<table align="center" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px auto 0; border-collapse: separate;">
<tr>
<td align="center" style="border-radius: 12px; background-color: #121217;">
<a href="{{ $receiptUrl }}" target="_blank" rel="noopener" style="display: inline-block; padding: 14px 28px; font-size: 16px; line-height: 20px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 12px; {{ $font }}">View / Download Receipt</a>
</td>
</tr>
</table>
{{-- Guarded so a missing URL costs the reader a button, never the whole receipt:
     an undefined variable here would fail the send and the payer would get no
     receipt at all. Its presence is asserted in ReceiptMailRenderingTest. --}}
@endisset
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top: 28px; border: 1px solid #D1D1DB; border-radius: 16px; border-collapse: separate; background-color: #ffffff;">
<tr>
<td style="padding: 20px 20px 16px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse;">
<tr>
@if($school?->logoUrl())
<td width="48" valign="middle" style="width: 48px; padding-right: 14px;"><img src="{{ $school->logoUrl() }}" width="48" height="48" alt="{{ $schoolName }} logo" style="display: block; width: 48px; height: 48px; border-radius: 12px; border: 1px solid #E9E9EE;"></td>
@elseif($schoolName)
<td width="48" valign="middle" style="width: 48px; padding-right: 14px;"><table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: separate;"><tr><td align="center" valign="middle" width="48" height="48" style="width: 48px; height: 48px; border-radius: 12px; background-color: #5423E7; color: #ffffff; font-size: 20px; font-weight: 700; line-height: 48px; {{ $font }}">{{ mb_substr($schoolName, 0, 1) }}</td></tr></table></td>
@endif
<td valign="middle">
<div style="font-size: 18px; line-height: 1.25; font-weight: 700; color: #121217; {{ $font }}">{{ $schoolName ?? 'Payment receipt' }}</div>
@if($school?->address)
<div style="margin-top: 2px; font-size: 13px; line-height: 1.4; color: #6C6C89; {{ $font }}">{{ $school->address }}</div>
@endif
@if($school?->phone || $school?->email)
<div style="font-size: 13px; line-height: 1.4; color: #6C6C89; word-break: break-word; {{ $font }}">{{ $school->phone }}{{ $school->phone && $school->email ? ' · ' : '' }}{{ $school->email }}</div>
@endif
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding: 0 20px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; border-top: 1px solid #E9E9EE;">
<tr>
<td colspan="2" style="{{ $sectionTitle }}">Payment</td>
</tr>
<tr>
<td style="{{ $label }}">Reference:</td>
<td style="{{ $value }} font-family: Menlo, Consolas, 'Courier New', monospace; font-size: 13px; word-break: break-all;">{{ $transaction->reference ?? '—' }}</td>
</tr>
<tr>
<td style="{{ $label }}">Date:</td>
<td style="{{ $value }}">{{ $paidAt?->format('d M Y, h:i A') ?? '—' }}</td>
</tr>
@if($method)
<tr>
<td style="{{ $label }}">Payment Method:</td>
<td style="{{ $value }}">{{ $method }}</td>
</tr>
@endif
<tr>
<td style="{{ $label }}">Status:</td>
<td style="{{ $value }}"><span style="display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background-color: {{ $isSuccess ? '#DCFCE7' : '#F7F7F8' }}; color: {{ $isSuccess ? '#166534' : '#6C6C89' }}; {{ $font }}">{{ $isSuccess ? 'Successful' : ucfirst($transaction->status) }}</span></td>
</tr>
@if($transaction->name)
<tr>
<td style="{{ $label }}">Paid By:</td>
<td style="{{ $value }}">{{ $transaction->name }}</td>
</tr>
@endif
@if($transaction->email)
<tr>
<td style="{{ $label }}">Email:</td>
<td style="{{ $value }} font-weight: 400; color: #6C6C89; word-break: break-all;">{{ $transaction->email }}</td>
</tr>
@endif
</table>
</td>
</tr>
@if($transaction->hasStudent() || $transaction->term_name)
<tr>
<td style="padding: 0 20px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; border-top: 1px solid #E9E9EE; margin-top: 12px;">
<tr>
<td colspan="2" style="{{ $sectionTitle }}">Student</td>
</tr>
@if($transaction->hasStudent())
<tr>
<td style="{{ $label }}">Student Name:</td>
<td style="{{ $value }}">{{ $transaction->student_name ?? '—' }}</td>
</tr>
@if($transaction->student_class)
<tr>
<td style="{{ $label }}">Class:</td>
<td style="{{ $value }}">{{ $transaction->student_class }}</td>
</tr>
@endif
@if($transaction->student_admission_number)
<tr>
<td style="{{ $label }}">Admission Number:</td>
<td style="{{ $value }} font-family: Menlo, Consolas, 'Courier New', monospace; font-size: 13px;">{{ $transaction->student_admission_number }}</td>
</tr>
@endif
@endif
@if($transaction->session_name || $transaction->term_name)
<tr>
<td style="{{ $label }}">Session / Term:</td>
<td style="{{ $value }}">{{ $transaction->session_name ?? '—' }}@if($transaction->term_name), {{ $transaction->term_name }}@endif</td>
</tr>
@endif
</table>
</td>
</tr>
@endif
<tr>
<td style="padding: 0 20px 16px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; border-top: 1px solid #E9E9EE; margin-top: 12px;">
<tr>
<td colspan="2" style="{{ $sectionTitle }}">Fee</td>
</tr>
@if($categoryName)
<tr>
<td style="{{ $label }}">Category:</td>
<td style="{{ $value }}">{{ $categoryName }}</td>
</tr>
@endif
@if($feeName)
<tr>
<td style="{{ $label }}">Fee Type:</td>
<td style="{{ $value }}">{{ $feeName }}</td>
</tr>
@endif
@if($receipt['quantity'] > 1)
<tr>
<td style="{{ $label }}">Unit Price × Qty:</td>
<td style="{{ $value }}">{{ $money($receipt['unit_price']) }} × {{ $receipt['quantity'] }}</td>
</tr>
@endif
<tr>
<td style="{{ $label }}">Fee Subtotal:</td>
<td style="{{ $value }}">{{ $money($receipt['fee_subtotal']) }}</td>
</tr>
@if($receipt['has_service_fee'])
<tr>
<td style="{{ $label }}">Service Fee:</td>
<td style="{{ $value }}">{{ $money($receipt['service_fee']) }}</td>
</tr>
@endif
</table>
</td>
</tr>
<tr>
<td style="padding: 16px 20px; background-color: #F7F7F8; border-top: 1px solid #E9E9EE; border-radius: 0 0 16px 16px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse;">
<tr>
<td valign="middle" style="font-size: 14px; font-weight: 700; color: #121217; white-space: nowrap; {{ $font }}">Total Amount Paid:</td>
<td valign="middle" align="right" style="font-size: 22px; line-height: 1.2; font-weight: 800; letter-spacing: -0.3px; color: #121217; text-align: right; padding-left: 12px; white-space: nowrap; {{ $font }}">{{ $money($receipt['total']) }}</td>
</tr>
</table>
</td>
</tr>
</table>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse;">
<tr>
<td style="padding: 20px 4px 0; font-size: 13px; line-height: 1.5; color: #6C6C89; {{ $font }}">This receipt is official proof of payment for the transaction above. Keep it for your records and quote the reference in any enquiry to the school.</td>
</tr>
@if($school?->receipt_footer)
<tr>
<td style="padding: 10px 4px 0; font-size: 13px; line-height: 1.5; color: #121217; white-space: pre-line; {{ $font }}">{{ $school->receipt_footer }}</td>
</tr>
@endif
</table>
@slot('footer')
@component('mail::footer')
@if($schoolName)
{{ implode(' · ', array_filter([$schoolName, $school->phone, $school->email])) }}

@endif
Receipts are issued by @include('marketing.partials.brand-name') on behalf of the school. Payments are processed securely by Paystack.
@endcomponent
@endslot
@endcomponent
