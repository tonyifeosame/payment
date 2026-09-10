@component('mail::message')
# Official Payment Receipt

@php
	// The transaction's own school_id is the authoritative source. This used to be
	// derived through category->school and subcategory->school, which made the
	// school's name on an ALREADY-ISSUED receipt depend on rows that outlive it:
	// transactions.category_id and subcategory_id are nullOnDelete, so deleting a
	// fee type silently stripped the school's branding from historical receipts,
	// and a category repointed to another school would have printed that school's
	// name on this school's receipt.
	$schoolName = $transaction->school?->name;
@endphp

@if($schoolName)
## {{ $schoolName }}
@endif

Thank you for your payment. Here are your transaction details:

**Receipt Date:** {{ $transaction->created_at?->format('M d, Y') }} {{ $transaction->created_at?->format('h:i A') }}

---

### Payer Information
- **Full Name:** {{ $transaction->name ?? '—' }}
- **Email Address:** {{ $transaction->email ?? '—' }}

### Payment Details
- **Reference Number:** {{ $transaction->reference ?? '—' }}
- **Payment Method:** {{ $transaction->payment_method ?? '—' }}
- **Status:** {{ ucfirst($transaction->status) }}

@php
	$receipt = $transaction->receiptBreakdown();
@endphp

### Transaction
| Category | Fee Type | Unit Price | Qty | Line Total |
|---|---|---|---|---|
| {{ $transaction->category_name ?? optional($transaction->category)->name }} | {{ $transaction->subcategory_name ?? optional($transaction->subcategory)->name }} | ₦{{ number_format($receipt['unit_price'], 2) }} | {{ $receipt['quantity'] }} | ₦{{ number_format($receipt['fee_subtotal'], 2) }} |

@if($receipt['has_service_fee'])
**Fee Subtotal:** ₦{{ number_format($receipt['fee_subtotal'], 2) }}

**Service Fee:** ₦{{ number_format($receipt['service_fee'], 2) }}

@endif
**Total Amount Paid:** ₦{{ number_format($receipt['total'], 2) }}

@isset($receiptUrl)
@component('mail::button', ['url' => $receiptUrl])
View / Download Receipt
@endcomponent

{{-- Guarded so a missing URL costs the reader a button, never the whole receipt:
     an undefined variable here would fail the send and the payer would get no
     receipt at all. Its presence is asserted in ReceiptMailRenderingTest. --}}
@endisset

---

> This receipt serves as official proof of payment for the transaction detailed above. Please keep this for your records. For any queries, contact the school administration with your reference number.

Thanks for choosing us!
@endcomponent
