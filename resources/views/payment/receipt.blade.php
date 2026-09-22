<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        // transactions.school_id is the authoritative source (see the email template
        // for why); the relation walk is only a fallback for rows that predate school_id.
        $school = $transaction->school
                  ?? optional($transaction->category)->school
                  ?? optional($transaction->subcategory)->school;
        $receipt = $transaction->receiptBreakdown();
        $paidAt = \App\Support\BusinessTime::display($transaction->paid_at ?? $transaction->created_at);
        $isSuccess = $transaction->status === 'success';
        $money = fn ($n) => '₦'.number_format((float) $n, 2);
        // Paystack channels arrive as snake_case ("bank_transfer"); the pre-settlement
        // placeholder is the provider name itself.
        $method = $transaction->payment_method
            ? ucfirst(str_replace('_', ' ', (string) $transaction->payment_method))
            : null;
        $backUrl = $school?->slug
            ? route('public.payment', ['school' => $school->slug])
            : route('payment.index');
    @endphp
    <title>Payment receipt{{ $school ? ' — '.$school->name : '' }}</title>
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @include('marketing.partials.head-tokens')
    <style type="text/tailwindcss">
        @layer components {
            /* Receipt rows: label left, value right; values may wrap on 375px phones
               (UUID references) without ever forcing horizontal scroll. */
            .receipt-section { @apply border-t border-brand-ash/60 px-5 py-4 sm:px-6; }
            .receipt-section-title { @apply text-xs font-semibold uppercase tracking-[0.12em] text-brand-slate; }
            .receipt-rows { @apply mt-2 divide-y divide-brand-fog; }
            .receipt-row { @apply flex items-start justify-between gap-4 py-2.5; }
            .receipt-row dt { @apply shrink-0 text-sm text-brand-slate; }
            .receipt-row dd { @apply min-w-0 break-words text-right text-sm font-semibold text-brand-obsidian; }
        }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .receipt-card { box-shadow: none !important; border: 1px solid #D1D1DB; }
        }
    </style>
</head>
<body class="min-h-screen bg-brand-fog text-brand-obsidian">

    {{-- Top bar: brand + what this page is. Not a link — parents should stay on their receipt. --}}
    <header class="border-b border-brand-ash/60 bg-white no-print">
        <div class="mx-auto flex h-14 max-w-5xl items-center justify-between px-4 sm:px-6">
            <span class="inline-flex items-center gap-2 font-display text-lg font-bold">
                @include('marketing.partials.logo-mark', ['size' => 28])
                @include('marketing.partials.brand-name')
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-slate">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h5M10 17h5"/></svg>
                Official receipt
            </span>
        </div>
    </header>

    <main class="mx-auto max-w-md px-4 pb-10 pt-8 sm:px-6 lg:max-w-2xl lg:pb-16 lg:pt-14">

        {{-- 1. Outcome --}}
        <section class="text-center" aria-labelledby="receipt-heading">
            @if($isSuccess)
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-600 text-white" aria-hidden="true">
                    <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
                </span>
                <h1 id="receipt-heading" class="mt-5 font-display text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl">Payment successful</h1>
                <p class="mx-auto mt-3 max-w-sm text-base text-brand-slate">
                    Thank you{{ $transaction->name ? ', '.$transaction->name : '' }}. Your payment of
                    <span class="font-semibold text-brand-obsidian">{{ $money($receipt['total']) }}</span>{{ $school ? ' to '.$school->name : '' }}
                    has been received and your receipt is ready.
                </p>
            @else
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-brand-ash text-brand-obsidian" aria-hidden="true">
                    <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </span>
                <h1 id="receipt-heading" class="mt-5 font-display text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl">Payment {{ strtolower($transaction->status) }}</h1>
                <p class="mx-auto mt-3 max-w-sm text-base text-brand-slate">This payment has not been confirmed as successful. The details recorded for it are below.</p>
            @endif
        </section>

        {{-- 2. Primary action --}}
        <div class="mt-6 no-print lg:text-center">
            <a href="{{ $downloadUrl }}" class="btn-obsidian w-full text-lg lg:w-auto lg:min-w-[22rem] lg:px-10">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14"/></svg>
                Download receipt (PDF)
            </a>
        </div>

        {{-- 3. Receipt card --}}
        <article class="receipt-card mt-6 overflow-hidden rounded-3xl bg-white shadow-sm" aria-label="Receipt details">

            {{-- School --}}
            <div class="flex items-center gap-4 px-5 py-5 sm:px-6">
                @if($school?->logoUrl())
                    <img src="{{ $school->logoUrl() }}" alt="{{ $school->name }} logo" class="h-14 w-14 shrink-0 rounded-2xl border border-brand-ash/60 bg-white object-contain">
                @elseif($school)
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand-violet font-display text-xl font-bold text-white" aria-hidden="true">{{ mb_substr($school->name, 0, 1) }}</span>
                @endif
                <div class="min-w-0">
                    <h2 class="font-display text-xl font-bold leading-tight tracking-tight">{{ $school?->name ?? 'Payment receipt' }}</h2>
                    @if($school?->address)<p class="mt-0.5 text-sm text-brand-slate">{{ $school->address }}</p>@endif
                    @if($school?->phone || $school?->email)
                        <p class="text-sm text-brand-slate break-words">{{ $school->phone }}{{ $school->phone && $school->email ? ' · ' : '' }}{{ $school->email }}</p>
                    @endif
                </div>
            </div>

            {{-- Payment --}}
            <section class="receipt-section" aria-labelledby="section-payment">
                <h3 id="section-payment" class="receipt-section-title">Payment</h3>
                <dl class="receipt-rows">
                    <div class="receipt-row">
                        <dt>Reference</dt>
                        <dd class="font-mono text-[13px] break-all">{{ $transaction->reference ?? '—' }}</dd>
                    </div>
                    <div class="receipt-row">
                        <dt>Date</dt>
                        <dd>{{ $paidAt ? $paidAt->format('d M Y, h:i A').' '.\App\Support\BusinessTime::label() : '—' }}</dd>
                    </div>
                    @if($method)
                        <div class="receipt-row">
                            <dt>Payment method</dt>
                            <dd>{{ $method }}</dd>
                        </div>
                    @endif
                    <div class="receipt-row">
                        <dt>Status</dt>
                        <dd>
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $isSuccess ? 'bg-green-50 text-green-800' : 'bg-brand-fog text-brand-slate' }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $isSuccess ? 'bg-green-600' : 'bg-brand-slate' }}" aria-hidden="true"></span>
                                {{ $isSuccess ? 'Successful' : ucfirst($transaction->status) }}
                            </span>
                        </dd>
                    </div>
                    @if($transaction->name || $transaction->email)
                        <div class="receipt-row">
                            <dt>Paid by</dt>
                            <dd>
                                @if($transaction->name)<span class="block">{{ $transaction->name }}</span>@endif
                                @if($transaction->email)<span class="block font-normal text-brand-slate break-all">{{ $transaction->email }}</span>@endif
                            </dd>
                        </div>
                    @endif
                </dl>
            </section>

            {{-- Student: only when the payment was recorded against a real student. The
                 receipt itself is only reachable by the payer, a signed link or the
                 owning school (see PaymentController::authorizeReceipt), so the full
                 admission number is shown here exactly as before; the public search is
                 where it is masked. --}}
            @if($transaction->hasStudent())
                <section class="receipt-section" aria-labelledby="section-student">
                    <h3 id="section-student" class="receipt-section-title">Student</h3>
                    <dl class="receipt-rows">
                        <div class="receipt-row">
                            <dt>Name</dt>
                            <dd>{{ $transaction->student_name ?? '—' }}</dd>
                        </div>
                        @if($transaction->student_class)
                            <div class="receipt-row">
                                <dt>Class</dt>
                                <dd>{{ $transaction->student_class }}</dd>
                            </div>
                        @endif
                        @if($transaction->student_admission_number)
                            <div class="receipt-row">
                                <dt>Admission No.</dt>
                                <dd class="font-mono text-[13px]">{{ $transaction->student_admission_number }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>
            @endif

            {{-- Fee --}}
            <section class="receipt-section" aria-labelledby="section-fee">
                <h3 id="section-fee" class="receipt-section-title">Fee</h3>
                <dl class="receipt-rows">
                    @php
                        $categoryName = $transaction->category_name ?? optional($transaction->category)->name;
                        $feeName = $transaction->subcategory_name ?? optional($transaction->subcategory)->name;
                    @endphp
                    @if($categoryName)
                        <div class="receipt-row">
                            <dt>Category</dt>
                            <dd>{{ $categoryName }}</dd>
                        </div>
                    @endif
                    @if($feeName)
                        <div class="receipt-row">
                            <dt>Fee type</dt>
                            <dd>{{ $feeName }}</dd>
                        </div>
                    @endif
                    @if($transaction->session_name || $transaction->term_name)
                        <div class="receipt-row">
                            <dt>Session / Term</dt>
                            <dd>{{ $transaction->session_name ?? '—' }}@if($transaction->term_name), {{ $transaction->term_name }}@endif</dd>
                        </div>
                    @endif
                    <div class="receipt-row">
                        <dt>Fee amount{{ $receipt['quantity'] > 1 ? ' ('.$receipt['quantity'].' × '.$money($receipt['unit_price']).')' : '' }}</dt>
                        <dd>{{ $money($receipt['fee_subtotal']) }}</dd>
                    </div>
                    @if($receipt['has_service_fee'])
                        <div class="receipt-row">
                            <dt>Service fee</dt>
                            <dd>{{ $money($receipt['service_fee']) }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            {{-- Total: the one number a parent looks for. --}}
            <div class="flex items-center justify-between gap-4 border-t border-brand-ash/60 bg-brand-fog px-5 py-5 sm:px-6">
                <span class="font-display text-base font-bold">Total paid</span>
                <span class="font-display text-2xl font-extrabold tracking-tight sm:text-3xl">{{ $money($receipt['total']) }}</span>
            </div>
        </article>

        {{-- 4. Email delivery. Receipts are queued to the payer's email on settlement
             (PaymentSettlementService::queueReceipt); there is no re-send endpoint. --}}
        @if($isSuccess && $transaction->email)
            <p class="mt-5 text-center text-sm text-brand-slate no-print">
                <span class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 8l9 6 9-6"/></svg>
                    A copy of this receipt has been emailed to
                </span>
                <span class="block break-all font-semibold text-brand-obsidian">{{ $transaction->email }}</span>
            </p>
        @endif

        {{-- 5. Back --}}
        <div class="mt-5 no-print lg:text-center">
            <a href="{{ $backUrl }}" class="btn-outline w-full lg:w-auto lg:min-w-[22rem] lg:px-10">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m0 0l6-6m-6 6l6 6"/></svg>
                Back to {{ $school ? $school->name : 'payment page' }}
            </a>
        </div>

        <footer class="mt-10 space-y-3 text-center text-xs text-brand-slate">
            {{-- L3: only a settled payment is proof of one. A pending or failed
                 receipt is a record of an attempt, and says so instead. --}}
            @if($isSuccess)
                <p>This receipt is official proof of payment. Keep it for your records and quote the reference in any enquiry to the school.</p>
            @else
                <p>This is a record of a payment attempt, not proof of payment. Quote the reference in any enquiry to the school.</p>
            @endif
            @if($school?->receipt_footer)
                <p class="whitespace-pre-line">{{ $school->receipt_footer }}</p>
            @endif
            <p class="hidden print:block">Generated {{ now()->format('d M Y, h:i A') }} · computer-generated, no signature required.</p>
            <p class="inline-flex items-center gap-1.5 pt-2 font-display text-sm font-bold text-brand-obsidian">
                @include('marketing.partials.logo-mark', ['size' => 20])
                @include('marketing.partials.brand-name')
            </p>
        </footer>
    </main>
</body>
</html>
