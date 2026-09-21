@extends('layouts.admin')

@section('title', 'Settings')
@section('eyebrow', 'School settings')
@section('heading', 'Settings')
@section('subheading', 'Manage your school details, payout account, receipts, and account security.')
@section('inline-errors', '1')

@section('content')
@php
    $s = ['school' => $school->slug];
    $masked = $school->account_number ? str_repeat('•', max(strlen($school->account_number) - 4, 0)).substr($school->account_number, -4) : null;
    $pw = $errors->password;
    $field = fn (string $name, $bag = null) => ($bag ?? $errors)->has($name);
@endphp

<div class="grid grid-cols-1 gap-6 xl:grid-cols-5 xl:items-start">

    {{-- School profile + receipts: the existing single update endpoint, shown as two cards. --}}
    <form method="POST" action="{{ route('school.settings.update', $s) }}" enctype="multipart/form-data" class="space-y-6 xl:col-span-3" aria-label="School profile and receipt settings">
        @csrf
        @method('PUT')

        <section class="card p-5 sm:p-6" aria-labelledby="profile-heading">
            <h2 id="profile-heading" class="font-display text-lg font-bold tracking-tight">School profile</h2>
            <p class="mt-1 text-sm text-brand-slate">Shown to parents on your payment page and receipts.</p>

            <div class="mt-5 space-y-5">
                <div>
                    <label for="name" class="field-label">School name</label>
                    <input id="name" name="name" value="{{ old('name', $school->name) }}" class="field-input {{ $field('name') ? 'field-input-error' : '' }}" required maxlength="255" autocomplete="organization" aria-describedby="name-help{{ $field('name') ? ' name-error' : '' }}" @if($field('name')) aria-invalid="true" @endif>
                    <p id="name-help" class="field-help">This is also your login name. Your payment link (<span class="font-mono">/pay/{{ $school->slug }}</span>) does not change.</p>
                    @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="email" class="field-label">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $school->email) }}" class="field-input {{ $field('email') ? 'field-input-error' : '' }}" required maxlength="255" autocomplete="email" aria-describedby="email-help{{ $field('email') ? ' email-error' : '' }}" @if($field('email')) aria-invalid="true" @endif>
                        <p id="email-help" class="field-help">Receives payout notices and password resets.</p>
                        @error('email')<p id="email-error" class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="phone" class="field-label">Phone <span class="font-normal text-brand-slate">(optional)</span></label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone', $school->phone) }}" class="field-input {{ $field('phone') ? 'field-input-error' : '' }}" maxlength="30" placeholder="e.g. 0801 234 5678" autocomplete="tel" @if($field('phone')) aria-invalid="true" aria-describedby="phone-error" @endif>
                        @error('phone')<p id="phone-error" class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <label for="address" class="field-label">Address <span class="font-normal text-brand-slate">(optional)</span></label>
                    <input id="address" name="address" value="{{ old('address', $school->address) }}" class="field-input {{ $field('address') ? 'field-input-error' : '' }}" maxlength="255" autocomplete="street-address" @if($field('address')) aria-invalid="true" aria-describedby="address-error" @endif>
                    @error('address')<p id="address-error" class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <span class="field-label" id="logo-label">Logo</span>
                    <div class="mt-2 flex flex-col gap-4 sm:flex-row sm:items-start">
                        @if($school->logoUrl())
                            <img src="{{ $school->logoUrl() }}" alt="Current logo of {{ $school->name }}" class="h-20 w-20 shrink-0 rounded-2xl border border-brand-ash/60 bg-white object-contain p-1">
                        @else
                            <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl border-2 border-dashed border-brand-ash text-xs text-brand-slate" aria-hidden="true">No logo</div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <label for="logo" class="sr-only">Upload a new logo</label>
                            <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" class="block w-full min-h-[48px] rounded-xl border border-brand-ash bg-white text-sm text-brand-obsidian file:mr-3 file:min-h-[46px] file:cursor-pointer file:rounded-l-xl file:border-0 file:bg-brand-fog file:px-4 file:font-semibold file:text-brand-obsidian focus:border-brand-violet focus:outline-none focus:ring-4 focus:ring-brand-violet/20 {{ $field('logo') ? 'border-red-500' : '' }}" aria-describedby="logo-help{{ $field('logo') ? ' logo-error' : '' }}" @if($field('logo')) aria-invalid="true" @endif>
                            <p id="logo-help" class="field-help">PNG, JPG or WebP up to 1 MB. Shown on the payment page and receipts.</p>
                            @error('logo')<p id="logo-error" class="field-error">{{ $message }}</p>@enderror
                            @if($school->logoUrl())
                                <label class="mt-3 flex min-h-[48px] cursor-pointer items-center gap-3 text-sm">
                                    <input type="checkbox" name="remove_logo" value="1" class="h-5 w-5 rounded border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" @checked(old('remove_logo'))>
                                    <span>Remove the current logo</span>
                                </label>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card p-5 sm:p-6" aria-labelledby="receipt-heading">
            <h2 id="receipt-heading" class="font-display text-lg font-bold tracking-tight">Receipt settings</h2>
            <p class="mt-1 text-sm text-brand-slate">Receipts are emailed to parents after every successful payment and can be downloaded as PDF.</p>
            <div class="mt-5">
                <label for="receipt_footer" class="field-label">Receipt footer text <span class="font-normal text-brand-slate">(optional)</span></label>
                <textarea id="receipt_footer" name="receipt_footer" rows="3" class="field-input py-3 {{ $field('receipt_footer') ? 'field-input-error' : '' }}" maxlength="500" placeholder="e.g. For enquiries call the bursary on 0801 234 5678. Fees paid are not refundable." aria-describedby="receipt_footer-help{{ $field('receipt_footer') ? ' receipt_footer-error' : '' }}" @if($field('receipt_footer')) aria-invalid="true" @endif>{{ old('receipt_footer', $school->receipt_footer) }}</textarea>
                <p id="receipt_footer-help" class="field-help">Appears at the bottom of every receipt. Up to 500 characters.</p>
                @error('receipt_footer')<p id="receipt_footer-error" class="field-error">{{ $message }}</p>@enderror
            </div>
        </section>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
            <p class="text-sm text-brand-slate sm:mr-auto">Saves the profile and receipt settings. Your payout account and password are changed in their own sections.</p>
            <button type="submit" class="btn-obsidian">Save changes</button>
        </div>
    </form>

    <div class="space-y-6 xl:col-span-2">
        {{-- Payout account --}}
        <section class="card p-5 sm:p-6" aria-labelledby="payout-heading" id="payout-account">
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <h2 id="payout-heading" class="font-display text-lg font-bold tracking-tight">Payout account</h2>
                @if($school->account_name)
                    @include('admin._badge', ['status' => 'success', 'label' => 'Verified'])
                @endif
            </div>
            <p class="mt-1 text-sm text-brand-slate">Where your school's fee amounts are paid out for every confirmed payment.</p>
            <dl class="mt-4 divide-y divide-brand-fog text-sm">
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Bank</dt>
                    <dd class="text-right font-medium">{{ $school->bank ?? '—' }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Account number</dt>
                    <dd class="text-right font-mono font-medium"><span aria-hidden="true">{{ $masked ?? '—' }}</span>@if($masked)<span class="sr-only">ending in {{ substr($school->account_number, -4) }}</span>@endif</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Account name</dt>
                    <dd class="text-right font-semibold">{{ $school->account_name ?? '—' }}</dd>
                </div>
            </dl>
            <p class="mt-2 text-xs text-brand-slate">The account name is the one your bank confirmed — it cannot be typed in.</p>

            <details class="mt-5 rounded-2xl border border-brand-ash/60" @if($errors->hasAny(['bank', 'bank_code', 'account_number', 'current_password']) || old('account_number') !== null) open @endif>
                <summary class="flex min-h-[56px] cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-4 font-semibold hover:bg-brand-fog/60 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 [&::-webkit-details-marker]:hidden">
                    Change payout account
                    <svg class="h-5 w-5 shrink-0 text-brand-slate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </summary>
                <form method="POST" action="{{ route('school.settings.bank', $s) }}" class="space-y-5 border-t border-brand-ash/60 p-4 sm:p-5" id="bankForm"
                      data-confirm="All future payouts will be sent to the new account after your bank confirms it. A notice will be emailed to {{ $school->email }}."
                      data-confirm-title="Change the payout account?"
                      data-confirm-label="Verify and change account">
                    @csrf
                    @method('PUT')
                    <p class="text-sm text-brand-slate">The new account is checked with your bank before anything is saved, and you must confirm your password.</p>
                    <div>
                        <label for="bank" class="field-label">Bank</label>
                        <select id="bank" name="bank" class="field-input {{ $errors->hasAny(['bank', 'bank_code']) ? 'field-input-error' : '' }}" required aria-describedby="bank-help{{ $errors->hasAny(['bank', 'bank_code']) ? ' bank-error' : '' }}" @if($errors->hasAny(['bank', 'bank_code'])) aria-invalid="true" @endif>
                        <option value="">Loading banks…</option>
                        </select>
                        <input type="hidden" id="bank_code" name="bank_code" value="{{ old('bank_code') }}">
                        <p id="bank-help" class="field-help">Nigerian banks, listed by your payment provider.</p>
                        @if($errors->hasAny(['bank', 'bank_code']))<p id="bank-error" class="field-error">{{ $errors->first('bank') ?: $errors->first('bank_code') }}</p>@endif
                    </div>
                    <div>
                        <label for="account_number" class="field-label">Account number (NUBAN)</label>
                        <input id="account_number" name="account_number" value="{{ old('account_number') }}" class="field-input font-mono {{ $field('account_number') ? 'field-input-error' : '' }}" inputmode="numeric" pattern="\d{10}" maxlength="10" required autocomplete="off" aria-describedby="account-help resolvedName{{ $field('account_number') ? ' account_number-error' : '' }}" @if($field('account_number')) aria-invalid="true" @endif>
                        <p id="account-help" class="field-help">10 digits. We look the name up with your bank as you type; it is checked again when you save.</p>
                        <p id="resolvedName" class="mt-1.5 text-sm font-semibold" role="status" aria-live="polite"></p>
                        @error('account_number')<p id="account_number-error" class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="current_password" class="field-label">Confirm your password</label>
                        <input id="current_password" name="current_password" type="password" class="field-input {{ $field('current_password') ? 'field-input-error' : '' }}" required autocomplete="current-password" aria-describedby="bank-password-help{{ $field('current_password') ? ' current_password-error' : '' }}" @if($field('current_password')) aria-invalid="true" @endif>
                        <p id="bank-password-help" class="field-help">Required because this changes where your money goes.</p>
                        @error('current_password')<p id="current_password-error" class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn-obsidian w-full">Verify and change account</button>
                </form>
            </details>
        </section>

        {{-- Security --}}
        <section class="card scroll-mt-24 p-5 sm:p-6" aria-labelledby="security-heading" id="security">
            <h2 id="security-heading" class="font-display text-lg font-bold tracking-tight">Security</h2>
            <p class="mt-1 text-sm text-brand-slate">Change your password</p>
            @if($pw->any())
                <div class="mt-4 flex gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600 text-white" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 8v5m0 3.5v.5"/></svg>
                    </span>
                    <p class="pt-0.5 font-semibold">Your password was not changed. Please check the highlighted {{ Str::plural('field', $pw->count()) }} below.</p>
                </div>
            @endif
            <form method="POST" action="{{ route('school.settings.password', $s) }}" class="mt-5 space-y-5" autocomplete="off" aria-label="Change password">
                @csrf
                @method('PUT')
                @foreach([
                    ['current_password', 'Current password', 'current-password', null],
                    ['password', 'New password', 'new-password', 'At least 8 characters.'],
                    ['password_confirmation', 'Confirm new password', 'new-password', null],
                ] as [$pwName, $pwLabel, $pwAutocomplete, $pwHelp])
                    @php $pwErr = $field($pwName === 'password_confirmation' ? 'password' : $pwName, $pw) && ($pwName !== 'password_confirmation' || str_contains($pw->first('password'), 'confirmation')); @endphp
                    <div>
                        {{-- Element ids are prefixed: the bank form also has a current_password field. --}}
                        <label for="pw-{{ $pwName }}" class="field-label">{{ $pwLabel }}</label>
                        <div class="relative">
                            <input id="pw-{{ $pwName }}" name="{{ $pwName }}" type="password" class="field-input pr-24 {{ $pwErr ? 'field-input-error' : '' }}" required minlength="{{ $pwName === 'current_password' ? 1 : 8 }}" autocomplete="{{ $pwAutocomplete }}" aria-describedby="{{ $pwHelp ? 'pw-'.$pwName.'-help' : '' }}{{ $pwErr && $pwName !== 'password_confirmation' ? ' pw-'.$pwName.'-error' : '' }}" @if($pwErr) aria-invalid="true" @endif>
                            <button type="button" class="js-toggle-password absolute inset-y-0 right-0 inline-flex min-h-[48px] items-center rounded-r-xl px-4 text-sm font-semibold text-brand-slate hover:bg-brand-fog hover:text-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30" data-target="pw-{{ $pwName }}" aria-pressed="false" aria-label="Show {{ strtolower($pwLabel) }}" hidden>Show</button>
                        </div>
                        @if($pwHelp)<p id="pw-{{ $pwName }}-help" class="field-help">{{ $pwHelp }}</p>@endif
                        @if($pwName !== 'password_confirmation' && $pw->has($pwName))<p id="pw-{{ $pwName }}-error" class="field-error">{{ $pw->first($pwName) }}</p>@endif
                    </div>
                @endforeach
                <p class="text-sm text-brand-slate">You stay signed in after changing your password.</p>
                <button type="submit" class="btn-obsidian w-full">Change password</button>
            </form>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // Bank picker + live account-name preview. Lookups go through the server's
    // /api endpoints; the name shown here is a convenience — the name that gets
    // saved is resolved again server-side when the form is submitted.
    const bankSelect = document.getElementById('bank');
    const bankCode = document.getElementById('bank_code');
    const acct = document.getElementById('account_number');
    const resolved = document.getElementById('resolvedName');
    const oldBank = @json(old('bank'));

    fetch('/api/banks?country=nigeria').then(r => r.json()).then(data => {
        bankSelect.innerHTML = '<option value="">Select bank</option>';
        if (!data.ok) { bankSelect.innerHTML = ''; const o = document.createElement('option'); o.value = ''; o.textContent = 'Could not load banks' + (data.error ? ': ' + data.error : ''); bankSelect.appendChild(o); return; }
        data.banks.forEach(b => {
            const o = document.createElement('option');
            o.value = b.name; o.textContent = b.name; o.dataset.code = b.code;
            if (oldBank === b.name) o.selected = true;
            bankSelect.appendChild(o);
        });
        syncCode();
    }).catch(() => { bankSelect.innerHTML = '<option value="">Could not load banks</option>'; });

    function setResolved(text, ok) {
        resolved.textContent = text;
        resolved.className = 'mt-1.5 text-sm font-semibold ' + (ok === true ? 'text-green-800' : ok === false ? 'text-red-700' : 'text-brand-slate');
    }
    function syncCode() {
        const opt = bankSelect.options[bankSelect.selectedIndex];
        bankCode.value = opt && opt.dataset.code ? opt.dataset.code : '';
        preview();
    }
    async function preview() {
        setResolved('', null);
        if (acct.value.length !== 10 || !bankCode.value) return;
        setResolved('Checking with your bank…', null);
        try {
            const r = await fetch(`/api/resolve-account?account_number=${acct.value}&bank_code=${bankCode.value}`);
            const d = await r.json();
            if (d.ok && d.account_name) setResolved('Account name: ' + d.account_name, true);
            else setResolved(d.error || 'Could not verify this account', false);
        } catch (e) { setResolved('Could not verify this account', false); }
    }
    bankSelect.addEventListener('change', syncCode);
    acct.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 10); preview(); });

    // Show/hide password toggles (progressive enhancement; hidden without JS).
    document.querySelectorAll('.js-toggle-password').forEach(function (btn) {
        btn.hidden = false;
        btn.addEventListener('click', function () {
            const input = document.getElementById(btn.dataset.target);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + btn.getAttribute('aria-label').replace(/^(Show|Hide) /, ''));
        });
    });
})();
</script>
@endpush
