@component('mail::message')
# Payment Receipt

Thank you for your payment. Here are your transaction details:

- **Name:** {{ $transaction->name }}
- **Email:** {{ $transaction->email }}
- **Category:** {{ $transaction->category_name }}
- **Fee Type:** {{ $transaction->subcategory_name }}
- **Amount Paid:** ₦{{ number_format($transaction->amount, 2) }}
- **Reference:** {{ $transaction->reference }}
- **Date:** {{ $transaction->created_at->format('F j, Y, g:i a') }}

You can also download your receipt from your dashboard or the payment page.

Thanks for choosing us!

@endcomponent
