@component('mail::message')
# Official Payment Receipt

@php
	$schoolName = optional(optional($transaction->category)->school)->name
				  ?? optional($transaction->subcategory)->school->name
				  ?? null;
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

---

> This receipt serves as official proof of payment for the transaction detailed above. Please keep this for your records. For any queries, contact the school administration with your reference number.

Thanks for choosing us!
@endcomponent
