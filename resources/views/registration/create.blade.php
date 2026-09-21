@extends('layouts.marketing')

@section('title')
    Set up your school — @include('marketing.partials.brand-name')
@endsection
@section('meta_description', 'Create a school account to collect school fees online, send receipts automatically and track payouts to your bank.')

{{-- Slim header: logo + sign in. The full marketing nav would distract from the form. --}}
@section('nav')
    <header class="border-b border-brand-fog bg-white">
        <nav class="container-x flex h-16 items-center justify-between gap-6 sm:h-20" aria-label="Main">
            @include('marketing.partials.logo')
            <div class="flex items-center gap-3">
                <span class="hidden text-sm text-brand-slate sm:inline">Already have an account?</span>
                <a href="{{ route('admin.login') }}" class="btn-outline btn-sm">Sign in</a>
            </div>
        </nav>
    </header>
@endsection

@section('footer')
    <footer class="border-t border-brand-fog bg-white">
        <div class="container-x flex flex-col gap-2 py-6 text-xs text-brand-slate sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ date('Y') }} @include('marketing.partials.brand-name'). All rights reserved.</p>
            <p class="flex gap-4">
                <a href="{{ route('home') }}" class="hover:text-brand-obsidian">Homepage</a>
                <a href="{{ route('contact.show') }}" class="hover:text-brand-obsidian">Contact</a>
            </p>
        </div>
    </footer>
@endsection

@section('content')
@php
    // aria-describedby for a field: its inline error (if any) plus its helper text (if any).
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
    $inputClass = fn (string $field) => 'field-input'.($errors->has($field) ? ' field-input-error' : '');
@endphp

<div class="bg-brand-fog/50">
    <div class="container-x py-10 sm:py-12 lg:py-16">
        <div class="grid gap-8 lg:grid-cols-12 lg:gap-12">

            {{-- Brand panel --}}
            <aside class="lg:col-span-5" aria-labelledby="registration-heading">
                <div class="lg:sticky lg:top-10">
                    <span class="eyebrow-violet">School administration</span>
                    <h1 id="registration-heading" class="mt-5 font-display text-3xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian sm:text-4xl lg:text-5xl">
                        Set up your school
                    </h1>
                    <p class="mt-4 max-w-md text-lg text-brand-slate">
                        Start collecting school fees online and keep every payment organized in one place.
                    </p>

                    {{-- Verified capabilities only. Hidden on small screens so the form is reached quickly. --}}
                    <ul class="mt-8 hidden space-y-4 lg:block">
                        @foreach([
                            ['Parents pay online through Paystack', 'Using your school\'s own payment link, from any device.'],
                            ['Receipts are sent automatically', 'On screen, by email and as a PDF, after every successful payment.'],
                            ['Payouts to a verified bank account', 'We confirm the account name with your bank before it is saved.'],
                        ] as [$title, $body])
                            <li class="flex gap-3">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-violet text-white">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
                                </span>
                                <div>
                                    <p class="font-semibold text-brand-obsidian">{{ $title }}</p>
                                    <p class="mt-0.5 text-sm text-brand-slate">{{ $body }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-8 hidden rounded-3xl bg-white p-5 lg:block">
                        <p class="text-sm font-semibold text-brand-obsidian">What happens next</p>
                        <p class="mt-1 text-sm text-brand-slate">You'll go straight to your dashboard to create your academic session, then your fees and students. Your links are also emailed to you.</p>
                    </div>

                    <p class="mt-8 hidden text-sm lg:block">
                        <a href="{{ route('home') }}" class="inline-flex items-center gap-1.5 font-medium text-brand-slate hover:text-brand-obsidian">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
                            Back to homepage
                        </a>
                    </p>
                </div>
            </aside>

            {{-- Form card --}}
            <div class="lg:col-span-7">
                <div class="rounded-4xl bg-white p-6 shadow-[0_20px_60px_-30px_rgba(18,18,23,0.25)] sm:p-8 lg:p-10">

                    @if(session('error'))
                        <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800" role="alert">{{ session('error') }}</div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-5" role="alert" aria-labelledby="error-summary-heading">
                            <p id="error-summary-heading" class="font-semibold text-red-800">Please fix the following:</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form id="registration_form" action="{{ route('registration.store') }}" method="POST" class="space-y-10">
                        @csrf

                        {{-- School --}}
                        <fieldset class="space-y-5">
                            <legend class="font-display text-xl font-bold tracking-tight text-brand-obsidian">Your school</legend>

                            <div>
                                <label for="name" class="field-label">School name</label>
                                <input id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="organization"
                                       placeholder="e.g. Springfield Academy"
                                       class="{{ $inputClass('name') }}"
                                       @error('name') aria-invalid="true" @enderror
                                       @if($d = $describedBy('name')) aria-describedby="{{ $d }}" @endif>
                                @include('marketing.partials.field-error', ['field' => 'name'])
                            </div>

                            <div>
                                <label for="email" class="field-label">School email</label>
                                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" inputmode="email"
                                       placeholder="admin@yourschool.edu.ng"
                                       class="{{ $inputClass('email') }}"
                                       @error('email') aria-invalid="true" @enderror
                                       aria-describedby="{{ $describedBy('email', true) }}">
                                @include('marketing.partials.field-error', ['field' => 'email'])
                                <p id="email-help" class="field-help">Used to sign in. Your dashboard and payment links are sent here.</p>
                            </div>

                            <div>
                                <label for="address" class="field-label">School address <span class="font-normal text-brand-slate">(optional)</span></label>
                                <input id="address" name="address" type="text" value="{{ old('address') }}" autocomplete="street-address"
                                       placeholder="123 Education Street, Lagos"
                                       class="{{ $inputClass('address') }}"
                                       @error('address') aria-invalid="true" @enderror
                                       @if($d = $describedBy('address')) aria-describedby="{{ $d }}" @endif>
                                @include('marketing.partials.field-error', ['field' => 'address'])
                            </div>
                        </fieldset>

                        {{-- Administrator --}}
                        <fieldset class="space-y-5">
                            <legend class="font-display text-xl font-bold tracking-tight text-brand-obsidian">Administrator sign-in</legend>
                            <p class="!mt-1 text-sm text-brand-slate">You'll sign in with the school email above and this password.</p>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="admin_password" class="field-label">Password</label>
                                    <input id="admin_password" name="admin_password" type="password" required autocomplete="new-password"
                                           class="{{ $inputClass('admin_password') }}"
                                           @error('admin_password') aria-invalid="true" @enderror
                                           aria-describedby="{{ $describedBy('admin_password', true) }}">
                                    @include('marketing.partials.field-error', ['field' => 'admin_password'])
                                    <p id="admin_password-help" class="field-help">At least 8 characters.</p>
                                </div>
                                <div>
                                    <label for="admin_password_confirmation" class="field-label">Confirm password</label>
                                    <input id="admin_password_confirmation" name="admin_password_confirmation" type="password" required autocomplete="new-password"
                                           class="{{ $inputClass('admin_password') }}"
                                           @error('admin_password') aria-invalid="true" @enderror>
                                </div>
                            </div>

                            <label for="toggle_admin_pw" class="inline-flex min-h-[44px] cursor-pointer items-center gap-3 text-sm font-medium text-brand-obsidian">
                                <input id="toggle_admin_pw" type="checkbox" class="h-5 w-5 rounded border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/20">
                                Show passwords
                            </label>
                        </fieldset>

                        {{-- Bank --}}
                        <fieldset class="space-y-5">
                            <legend class="font-display text-xl font-bold tracking-tight text-brand-obsidian">Payout bank account</legend>
                            <p class="!mt-1 text-sm text-brand-slate">Where the school's share of each payment is sent. We confirm the account name with your bank before saving.</p>

                            <div>
                                <label for="bank" class="field-label">Bank</label>
                                <div class="relative">
                                    <select id="bank" name="bank" required
                                            class="{{ $inputClass('bank') }} appearance-none pr-11"
                                            @error('bank') aria-invalid="true" @enderror
                                            @if($d = $describedBy('bank')) aria-describedby="{{ $d }}" @endif>
                                        <option value="">Loading banks...</option>
                                    </select>
                                    <svg class="pointer-events-none absolute right-4 top-1/2 mt-1 h-5 w-5 -translate-y-1/2 text-brand-slate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                                </div>
                                <input type="hidden" id="bank_code" name="bank_code" value="{{ old('bank_code') }}">
                                @include('marketing.partials.field-error', ['field' => 'bank'])
                                @include('marketing.partials.field-error', ['field' => 'bank_code'])
                            </div>

                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="account_number" class="field-label">Account number</label>
                                    <input id="account_number" name="account_number" type="text" value="{{ old('account_number') }}" required
                                           inputmode="numeric" autocomplete="off" maxlength="10" placeholder="0123456789"
                                           class="{{ $inputClass('account_number') }}"
                                           @error('account_number') aria-invalid="true" @enderror
                                           aria-describedby="{{ $describedBy('account_number', true) }}">
                                    @include('marketing.partials.field-error', ['field' => 'account_number'])
                                    <p id="account_number-help" class="field-help">10-digit NUBAN account number.</p>
                                </div>
                                <div>
                                    <label for="account_name" class="field-label">Account name</label>
                                    <div class="relative">
                                        <input id="account_name" name="account_name" type="text" value="{{ old('account_name') }}" readonly
                                               placeholder="Shown after verification" autocomplete="off"
                                               class="{{ $inputClass('account_name') }} pr-11 bg-brand-fog text-brand-slate"
                                               aria-describedby="account_name-help account_name_message">
                                        <div id="account_name_status" class="pointer-events-none absolute right-4 top-1/2 mt-1 flex -translate-y-1/2 items-center" aria-hidden="true"></div>
                                    </div>
                                    <p id="account_name_message" class="field-error hidden" aria-live="polite"></p>
                                    <p id="account_name-help" class="field-help">Filled in automatically once the bank and account number are entered.</p>
                                    <span id="account_name_live" class="sr-only" aria-live="polite"></span>
                                </div>
                            </div>
                        </fieldset>

                        {{-- Submit --}}
                        <div class="space-y-4 border-t border-brand-fog pt-8">
                            <button id="register_button" type="submit" class="btn-obsidian w-full">
                                <span id="register_button_text">Create school account</span>
                                <svg id="register_spinner" class="hidden h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/>
                                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                                </svg>
                                <span id="register_button_busy" class="hidden">Creating your school…</span>
                            </button>
                            <p class="text-center text-sm text-brand-slate">
                                Already have an account?
                                <a href="{{ route('admin.login') }}" class="font-semibold text-brand-violet hover:underline">Sign in</a>
                            </p>
                            <p class="text-center text-sm lg:hidden">
                                <a href="{{ route('home') }}" class="text-brand-slate hover:text-brand-obsidian">&larr; Back to homepage</a>
                            </p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bankSelect = document.getElementById('bank');
    const bankCodeInput = document.getElementById('bank_code');
    const accountNumberInput = document.getElementById('account_number');
    const accountNameInput = document.getElementById('account_name');
    const accountNameStatus = document.getElementById('account_name_status');
    const accountNameMessage = document.getElementById('account_name_message');
    const accountNameLive = document.getElementById('account_name_live');
    const toggleAdminPw = document.getElementById('toggle_admin_pw');
    const adminPassword = document.getElementById('admin_password');
    const adminPasswordConfirm = document.getElementById('admin_password_confirmation');
    const registerButton = document.getElementById('register_button');
    const registerButtonText = document.getElementById('register_button_text');
    const registerButtonBusy = document.getElementById('register_button_busy');
    const registerSpinner = document.getElementById('register_spinner');

    let verifyController = null;

    const defaultAccountNamePlaceholder = accountNameInput.placeholder;

    const icons = {
        spinner: '<svg class="h-5 w-5 animate-spin text-brand-violet" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>',
        ok: '<svg class="h-5 w-5 text-green-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>',
        fail: '<svg class="h-5 w-5 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>',
        warn: '<svg class="h-5 w-5 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 8v4m0 4h.01"/><circle cx="12" cy="12" r="9"/></svg>',
    };

    function showAccountMessage(text) {
        accountNameMessage.textContent = text || '';
        accountNameMessage.classList.toggle('hidden', !text);
    }

    // Screen-reader announcement of the verification state.
    function announce(text) {
        accountNameLive.textContent = text || '';
    }

    function setBankSelectMessage(text) {
        bankSelect.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = text;
        bankSelect.appendChild(option);
    }

    async function loadBanks() {
        try {
            const response = await fetch('/api/banks?country=nigeria');
            const data = await response.json();

            if (data.ok && data.banks) {
                bankSelect.innerHTML = '<option value="">Select your bank</option>';
                data.banks.forEach(bank => {
                    const option = document.createElement('option');
                    option.value = bank.name;
                    option.textContent = bank.name;
                    option.dataset.code = bank.code;
                    bankSelect.appendChild(option);
                });
                // Restore the previously chosen bank after a validation round-trip.
                const previousCode = bankCodeInput.value;
                if (previousCode) {
                    const match = Array.from(bankSelect.options).find(o => o.dataset.code === previousCode);
                    if (match) {
                        bankSelect.value = match.value;
                        verifyAccount();
                    }
                }
            } else {
                // Keep the server's reason: "secret key is not configured" and
                // "temporarily unavailable" need very different responses.
                console.error('Bank list failed:', response.status, data.error);
                setBankSelectMessage('Failed to load banks' + (data.error ? ': ' + data.error : ''));
            }
        } catch (error) {
            console.error('Error loading banks:', error);
            setBankSelectMessage('Error loading banks');
        }
    }

    bankSelect.addEventListener('change', function() {
        const selectedOption = bankSelect.options[bankSelect.selectedIndex];
        if (selectedOption && selectedOption.dataset.code) {
            bankCodeInput.value = selectedOption.dataset.code;
        } else {
            bankCodeInput.value = '';
        }
        verifyAccount();
    });

    accountNumberInput.addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
        if (this.value.length === 10) {
            verifyAccount();
        } else {
            accountNameInput.value = '';
            accountNameInput.placeholder = defaultAccountNamePlaceholder;
            accountNameStatus.innerHTML = '';
            showAccountMessage('');
            announce('');
        }
    });

    async function verifyAccount() {
        const accountNumber = accountNumberInput.value;
        const bankCode = bankCodeInput.value;

        if (accountNumber.length !== 10 || !bankCode) {
            return;
        }

        if (verifyController) {
            verifyController.abort();
        }

        verifyController = new AbortController();

        accountNameInput.value = '';
        accountNameInput.placeholder = 'Verifying...';
        showAccountMessage('');
        announce('Verifying account name.');
        accountNameStatus.innerHTML = icons.spinner;

        try {
            const response = await fetch(`/api/resolve-account?account_number=${accountNumber}&bank_code=${bankCode}`, {
                signal: verifyController.signal
            });

            const data = await response.json();

            if (data.ok && data.account_name) {
                accountNameInput.value = data.account_name;
                accountNameInput.placeholder = defaultAccountNamePlaceholder;
                accountNameStatus.innerHTML = icons.ok;
                announce('Account verified: ' + data.account_name);
            } else {
                accountNameStatus.innerHTML = icons.fail;
                accountNameInput.placeholder = 'Not verified';
                showAccountMessage(data.error || 'Verification failed');
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Error verifying account:', error);
                accountNameInput.placeholder = 'Not verified';
                showAccountMessage('Could not reach the verification service. Please try again.');
                accountNameStatus.innerHTML = icons.warn;
            }
        }
    }

    toggleAdminPw.addEventListener('change', function() {
        const type = this.checked ? 'text' : 'password';
        adminPassword.type = type;
        adminPasswordConfirm.type = type;
    });

    document.getElementById('registration_form').addEventListener('submit', function() {
        registerButton.disabled = true;
        registerButton.setAttribute('aria-busy', 'true');
        registerButtonText.classList.add('hidden');
        registerSpinner.classList.remove('hidden');
        registerButtonBusy.classList.remove('hidden');
    });

    loadBanks();
});
</script>
@endsection
