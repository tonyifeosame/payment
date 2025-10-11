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

### Transaction
| Category | Fee Type | Unit Price | Qty | Line Total |
|---|---|---|---|---|
| {{ $transaction->category_name ?? optional($transaction->category)->name }} | {{ $transaction->subcategory_name ?? optional($transaction->subcategory)->name }} | ₦{{ number_format((float)($transaction->meta_data['base_amount'] ?? $transaction->amount ?? 0) / max((int)($transaction->meta_data['quantity'] ?? 1),1), 2) }} | {{ (int)($transaction->meta_data['quantity'] ?? 1) }} | ₦{{ number_format((float)($transaction->meta_data['base_amount'] ?? $transaction->amount ?? 0), 2) }} |

**Total Amount:** ₦{{ number_format((float)($transaction->meta_data['base_amount'] ?? $transaction->amount ?? 0), 2) }}

---

> This receipt serves as official proof of payment for the transaction detailed above. Please keep this for your records. For any queries, contact the school administration with your reference number.

Thanks for choosing us!
@endcomponent
