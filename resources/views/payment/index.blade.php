<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $school->name }} — School fees payment</title>
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @include('marketing.partials.head-tokens')
    <style type="text/tailwindcss">
        @layer base {
            /* Room for the sticky pay bar on phones so a focused field is never hidden behind it. */
            @media (max-width: 1023px) { html { scroll-padding-bottom: 7.5rem; } }
        }
        @layer components {
            .step-card { @apply rounded-3xl bg-white p-5 shadow-sm sm:p-6; }
            .step-heading { @apply flex items-center gap-3 font-display text-lg font-bold tracking-tight text-brand-obsidian; }
            .step-num { @apply flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-violet text-sm font-bold text-white; }
            .field-select { @apply field-input appearance-none pr-11 disabled:bg-brand-fog disabled:text-brand-slate; }
            .select-chevron { @apply pointer-events-none absolute right-4 top-1/2 mt-1 h-5 w-5 -translate-y-1/2 text-brand-slate; }
        }
    </style>
</head>
<body class="min-h-screen bg-brand-fog text-brand-obsidian">
@php
    // aria-describedby: the field's inline error (if any) plus its helper text (if any).
    $describedBy = function (string $field, bool $hasHelp = false) use ($errors): ?string {
        $ids = [];
        if ($errors->has($field)) {
            $ids[] = $field.'-error';
        }
        if ($hasHelp) {
            $ids[] = $field.'-help';
        }

        return $ids ? implode(' ', $ids) : null;
    };
    $inputClass = fn (string $field, string $base = 'field-input') => $base.($errors->has($field) ? ' field-input-error' : '');
    $hasSessions = $sessionsForJs->isNotEmpty();
@endphp

    {{-- Top bar: brand + trust cue. Deliberately not a link — parents arriving from a
         school's WhatsApp/SMS link should stay on the checkout. --}}
    <header class="border-b border-brand-ash/60 bg-white">
        <div class="mx-auto flex h-14 max-w-5xl items-center justify-between px-4 sm:px-6">
            <span class="inline-flex items-center gap-2 font-display text-lg font-bold">
                @include('marketing.partials.logo-mark', ['size' => 28])
                @include('marketing.partials.brand-name')
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-slate">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                Secure payment
            </span>
        </div>
    </header>

    <main class="mx-auto max-w-md px-4 pb-32 pt-6 sm:px-6 lg:max-w-5xl lg:pb-16 lg:pt-10">

        {{-- School identity --}}
        <section class="flex items-center gap-4" aria-label="School">
            @if($school->logoUrl())
                <img src="{{ $school->logoUrl() }}" alt="{{ $school->name }} logo" class="h-14 w-14 shrink-0 rounded-2xl border border-brand-ash/60 bg-white object-contain">
            @else
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand-violet font-display text-xl font-bold text-white" aria-hidden="true">{{ mb_substr($school->name, 0, 1) }}</span>
            @endif
            <div class="min-w-0">
                <h1 class="font-display text-2xl font-extrabold leading-tight tracking-tight sm:text-3xl">{{ $school->name }}</h1>
                <p class="text-sm text-brand-slate">School fees payment</p>
            </div>
        </section>

        {{-- Outcome messages --}}
        @if(session('success'))
            <section class="mt-6 rounded-3xl border border-green-200 bg-green-50 p-5" role="status" aria-labelledby="success-heading">
                <div class="flex gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-green-600 text-white">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p id="success-heading" class="font-display text-lg font-bold text-green-900">Payment successful</p>
                        <p class="mt-1 text-sm text-green-800">{{ session('success') }}</p>
                    </div>
                </div>
                @if(session('last_transaction_id'))
                    <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                        <a href="{{ route('payment.receipt', session('last_transaction_id')) }}" class="btn-obsidian w-full sm:w-auto">View receipt</a>
                        <a href="{{ route('payment.receipt.download', session('last_transaction_id')) }}" class="btn-outline w-full sm:w-auto">Download PDF</a>
                    </div>
                @endif
            </section>
        @endif

        @if(session('error'))
            <section class="mt-6 rounded-3xl border border-red-200 bg-red-50 p-5" role="alert">
                <p class="font-display text-lg font-bold text-red-900">Payment not completed</p>
                <p class="mt-1 text-sm text-red-800">{{ session('error') }}</p>
                <p class="mt-2 text-sm text-red-800">Your details are still filled in below — check them and try again.</p>
            </section>
        @endif

        @if ($errors->any())
            <section class="mt-6 rounded-3xl border border-red-200 bg-red-50 p-5" role="alert" aria-labelledby="error-summary-heading">
                <p id="error-summary-heading" class="font-semibold text-red-800">Please fix the following:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <form id="paymentForm" action="{{ route('school.payment.initialize', ['school' => $school->slug]) }}" method="POST" class="mt-6 grid gap-5 lg:grid-cols-12 lg:gap-8">
            @csrf

            <div class="space-y-5 lg:col-span-7">

                @if($requiresStudent || $hasSessions)
                {{-- Step 1: who and when --}}
                <section class="step-card" aria-labelledby="step-student">
                    <h2 id="step-student" class="step-heading"><span class="step-num" aria-hidden="true">1</span>{{ $requiresStudent ? 'Find your student' : 'Session and term' }}</h2>

                    @if($hasSessions)
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div>
                            <label for="academic_session_id" class="field-label">Session</label>
                            <div class="relative">
                                <select name="academic_session_id" id="academic_session_id" class="{{ $inputClass('academic_session_id', 'field-select') }}"></select>
                                <svg class="select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                            </div>
                            @include('marketing.partials.field-error', ['field' => 'academic_session_id'])
                        </div>
                        <div>
                            <label for="academic_term_id" class="field-label">Term</label>
                            <div class="relative">
                                <select name="academic_term_id" id="academic_term_id" class="{{ $inputClass('academic_term_id', 'field-select') }}"></select>
                                <svg class="select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                            </div>
                            @include('marketing.partials.field-error', ['field' => 'academic_term_id'])
                        </div>
                    </div>
                    @endif

                    @if($requiresStudent)
                    <div class="mt-4 space-y-3" id="studentPicker" data-old-student='@json($oldStudent)'>
                        {{-- Only student_id is submitted. The server re-resolves it within this school;
                             the name/class/masked admission number shown here are for the parent, never for the server. --}}
                        <input type="hidden" id="student_id" name="student_id" value="{{ $oldStudent['id'] ?? '' }}">

                        <div class="relative" id="studentSearchWrap">
                            <label for="student_query" class="field-label">Student name</label>
                            <div class="relative">
                                <svg class="pointer-events-none absolute left-4 top-1/2 mt-1 h-5 w-5 -translate-y-1/2 text-brand-slate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/></svg>
                                <input type="text" id="student_query" autocomplete="off" autocapitalize="words" spellcheck="false" enterkeyhint="search"
                                       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="studentSuggestions" aria-haspopup="listbox"
                                       class="{{ $inputClass('student_id') }} pl-12"
                                       @error('student_id') aria-invalid="true" @enderror
                                       aria-describedby="{{ $describedBy('student_id', true) }}"
                                       placeholder="Start typing the student's name">
                            </div>
                            <p id="student_id-help" class="field-help">Type at least 2 letters of the name, or the admission number.</p>
                            <ul id="studentSuggestions" role="listbox" aria-label="Matching students" hidden
                                class="absolute left-0 right-0 z-30 mt-1 max-h-72 divide-y divide-brand-fog overflow-y-auto rounded-2xl border border-brand-ash bg-white shadow-[0_20px_50px_-20px_rgba(18,18,23,0.35)]"></ul>
                            <p id="studentSearchStatus" class="mt-1.5 text-sm text-brand-slate" aria-live="polite"></p>
                            @include('marketing.partials.field-error', ['field' => 'student_id'])
                        </div>

                        <div id="studentSelected" hidden class="rounded-2xl border border-brand-violet/30 bg-brand-violet/[0.06] p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-violet/15 text-sm font-bold text-brand-violet" id="selectedStudentInitials" aria-hidden="true"></span>
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-violet">Paying for</p>
                                        <p id="selectedStudentName" class="mt-0.5 break-words font-display text-lg font-bold leading-tight text-brand-obsidian"></p>
                                    </div>
                                </div>
                                <button type="button" id="studentChange" class="btn-outline btn-sm shrink-0">Change</button>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-3">
                                <div>
                                    <label for="student_admission_display" class="block text-xs font-semibold text-brand-slate">Admission number</label>
                                    <input type="text" id="student_admission_display" readonly tabindex="-1" aria-readonly="true"
                                           class="mt-1 block w-full rounded-xl border border-brand-ash/60 bg-white px-3 py-2 font-mono text-sm text-brand-obsidian">
                                </div>
                                <div>
                                    <label for="student_class_display" class="block text-xs font-semibold text-brand-slate">Class</label>
                                    <input type="text" id="student_class_display" readonly tabindex="-1" aria-readonly="true"
                                           class="mt-1 block w-full rounded-xl border border-brand-ash/60 bg-white px-3 py-2 text-sm text-brand-obsidian">
                                </div>
                            </div>
                        </div>
                    </div>
                    @endif
                </section>
                @endif

                {{-- Step 2: fee --}}
                <section class="step-card" aria-labelledby="step-fee">
                    <h2 id="step-fee" class="step-heading"><span class="step-num" aria-hidden="true">{{ ($requiresStudent || $hasSessions) ? 2 : 1 }}</span>Select the fee</h2>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="category" class="field-label">Category</label>
                            <div class="relative">
                                <select name="category_id" id="category" class="{{ $inputClass('category_id', 'field-select') }}"
                                        @error('category_id') aria-invalid="true" @enderror
                                        @if($d = $describedBy('category_id')) aria-describedby="{{ $d }}" @endif>
                                    <option value="">-- Select Category --</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                                <svg class="select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                            </div>
                            <span id="catError" class="field-error block empty:hidden"></span>
                            @include('marketing.partials.field-error', ['field' => 'category_id'])
                        </div>

                        <div>
                            <label for="subcategory" class="field-label">Fee type</label>
                            <div class="relative">
                                <select name="subcategory_id" id="subcategory" class="{{ $inputClass('subcategory_id', 'field-select') }}"
                                        @error('subcategory_id') aria-invalid="true" @enderror
                                        @if($d = $describedBy('subcategory_id')) aria-describedby="{{ $d }}" @endif>
                                    <option value="">-- Select Fee Type --</option>
                                </select>
                                <svg class="select-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                            </div>
                            <p id="unitPrice" class="field-help">Unit Price: ₦0</p>
                            <span id="subError" class="field-error block empty:hidden"></span>
                            @include('marketing.partials.field-error', ['field' => 'subcategory_id'])
                        </div>

                        <div id="quantityContainer">
                            <label for="quantity" class="field-label">Quantity</label>
                            <input type="number" name="quantity" id="quantity" value="{{ old('quantity', 1) }}" min="1" inputmode="numeric"
                                   class="{{ $inputClass('quantity') }}" required
                                   @error('quantity') aria-invalid="true" @enderror
                                   @if($d = $describedBy('quantity')) aria-describedby="{{ $d }}" @endif>
                            <span id="qtyError" class="field-error block empty:hidden"></span>
                            @include('marketing.partials.field-error', ['field' => 'quantity'])
                        </div>
                    </div>
                </section>

                {{-- Step 3: payer details (after the amount is known) --}}
                <section class="step-card" aria-labelledby="step-details">
                    <h2 id="step-details" class="step-heading"><span class="step-num" aria-hidden="true">{{ ($requiresStudent || $hasSessions) ? 3 : 2 }}</span>Your details</h2>
                    <p class="mt-1 text-sm text-brand-slate">Your receipt will be sent to this email address.</p>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="email" class="field-label">Email address</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email" inputmode="email" required
                                   placeholder="you@example.com"
                                   class="{{ $inputClass('email') }}"
                                   @error('email') aria-invalid="true" @enderror
                                   @if($d = $describedBy('email')) aria-describedby="{{ $d }}" @endif>
                            @include('marketing.partials.field-error', ['field' => 'email'])
                        </div>
                        <div>
                            <label for="name" class="field-label">Your name <span class="font-normal text-brand-slate">(optional)</span></label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" autocomplete="name"
                                   placeholder="Parent or guardian"
                                   class="{{ $inputClass('name') }}"
                                   @error('name') aria-invalid="true" @enderror
                                   @if($d = $describedBy('name')) aria-describedby="{{ $d }}" @endif>
                            @include('marketing.partials.field-error', ['field' => 'name'])
                        </div>
                    </div>
                </section>
            </div>

            {{-- Summary + pay. On phones the summary sits in the flow and the pay button is a
                 sticky bar; on desktop both live in a sticky right column. --}}
            <div class="lg:col-span-5">
                <div class="lg:sticky lg:top-6">
                    <section class="step-card" aria-labelledby="summary-heading">
                        <h2 id="summary-heading" class="font-display text-lg font-bold tracking-tight">Payment summary</h2>
                        <dl class="mt-4 space-y-2.5 text-sm">
                            @if($requiresStudent)
                            <div class="flex items-start justify-between gap-4"><dt class="text-brand-slate">Student</dt><dd id="summaryStudent" class="text-right font-semibold">—</dd></div>
                            @endif
                            @if($hasSessions)
                            <div class="flex items-start justify-between gap-4"><dt class="text-brand-slate">Term</dt><dd id="summaryTerm" class="text-right font-semibold">—</dd></div>
                            @endif
                            <div class="flex items-start justify-between gap-4"><dt class="text-brand-slate">Category</dt><dd id="summaryCategory" class="text-right font-semibold">—</dd></div>
                            <div class="flex items-start justify-between gap-4"><dt class="text-brand-slate">Fee</dt><dd id="summarySub" class="text-right font-semibold">—</dd></div>
                            <div class="flex items-start justify-between gap-4"><dt class="text-brand-slate">Fee amount</dt><dd id="summaryPrice" class="tabular-nums font-semibold">₦0</dd></div>
                            <div id="summaryQtyRow" class="flex items-start justify-between gap-4"><dt class="text-brand-slate">Quantity</dt><dd id="summaryQty" class="tabular-nums font-semibold">1</dd></div>
                            <div class="flex items-start justify-between gap-4"><dt class="text-brand-slate">Service fee ({{ isset($markupPercent) ? $markupPercent : 0 }}%)</dt><dd id="summaryFee" class="tabular-nums font-semibold">₦0</dd></div>
                            <div class="flex items-center justify-between gap-4 border-t border-brand-fog pt-3">
                                <dt class="font-display text-base font-bold">Total to pay</dt>
                                <dd id="summaryTotal" class="font-display text-2xl font-extrabold tabular-nums">₦0</dd>
                            </div>
                        </dl>

                        {{-- Kept for the existing JS; the server ignores these and recomputes from its own records. --}}
                        <label for="total" class="sr-only">Total amount including service fee</label>
                        <input type="text" id="total" name="total" class="sr-only" readonly tabindex="-1">
                        <input type="hidden" name="category_name" id="category_name">
                        <input type="hidden" name="subcategory_name" id="subcategory_name">
                        <input type="hidden" name="client_total" id="client_total">

                        {{-- Pay bar: fixed to the bottom on phones, inline on desktop. --}}
                        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-brand-ash/60 bg-white/95 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] backdrop-blur lg:static lg:mt-5 lg:border-0 lg:bg-transparent lg:p-0 lg:backdrop-blur-0">
                            <div class="mx-auto max-w-md lg:max-w-none">
                                <button id="submitBtn" type="submit" class="btn-obsidian w-full text-lg">
                                    <svg id="spinner" class="hidden h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/>
                                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                    </svg>
                                    <span id="submitText">Pay</span>
                                    <span id="submitTotal" class="tabular-nums">₦0</span>
                                </button>
                                <p class="mt-2 text-center text-[11px] text-brand-slate lg:text-xs">You'll be taken to Paystack to complete the payment.</p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </form>

        <footer class="mt-10 space-y-1 text-center text-xs text-brand-slate">
            @if($school->address)<p>{{ $school->address }}</p>@endif
            @if($school->phone || $school->email)<p>{{ $school->phone }} {{ $school->phone && $school->email ? '·' : '' }} {{ $school->email }}</p>@endif
            <p class="pt-2">Payments are processed securely by Paystack. A receipt is emailed after every successful payment.</p>
        </footer>
    </main>

@include('payment._script')
</body>
</html>
