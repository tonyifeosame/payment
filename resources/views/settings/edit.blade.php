@extends('layouts.admin')

@section('title', 'Settings')
@section('heading', 'School settings')
@section('subheading', 'What parents see on your payment page and receipts, and where your money is paid.')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
    <form method="POST" action="{{ route('school.settings.update', ['school' => $school->slug]) }}" enctype="multipart/form-data" class="card p-6 space-y-5">
        @csrf
        @method('PUT')
        <h2 class="font-bold text-slate-900 text-lg">Profile & branding</h2>

        <div>
            <label for="name" class="label">School name</label>
            <input id="name" name="name" value="{{ old('name', $school->name) }}" class="input" required maxlength="255">
            <p class="text-xs text-slate-500 mt-1">Used to log in. Your payment link (<span class="font-mono">/s/{{ $school->slug }}</span>) does not change.</p>
            @error('name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label for="email" class="label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $school->email) }}" class="input" required>
                @error('email')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="phone" class="label">Phone</label>
                <input id="phone" name="phone" value="{{ old('phone', $school->phone) }}" class="input" maxlength="30" placeholder="e.g. 0801 234 5678">
                @error('phone')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
        </div>
        <div>
            <label for="address" class="label">Address</label>
            <input id="address" name="address" value="{{ old('address', $school->address) }}" class="input" maxlength="255">
        </div>
        <div>
            <label for="logo" class="label">Logo</label>
            <div class="flex items-center gap-4">
                @if($school->logoUrl())
                    <img src="{{ $school->logoUrl() }}" alt="Current logo" class="w-16 h-16 rounded-lg object-contain border border-slate-200 bg-white">
                @else
                    <div class="w-16 h-16 rounded-lg border-2 border-dashed border-slate-300 flex items-center justify-center text-slate-400 text-xs">none</div>
                @endif
                <div class="flex-1">
                    <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" class="input !py-2">
                    <p class="text-xs text-slate-500 mt-1">PNG, JPG or WebP, up to 1 MB. Shown on the payment page and receipts.</p>
                    @if($school->logoUrl())
                        <label class="inline-flex items-center gap-2 text-sm mt-2"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
                    @endif
                </div>
            </div>
            @error('logo')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="receipt_footer" class="label">Receipt footer text</label>
            <textarea id="receipt_footer" name="receipt_footer" rows="3" class="input" maxlength="500" placeholder="e.g. For enquiries call the bursary on 0801 234 5678. Fees paid are not refundable.">{{ old('receipt_footer', $school->receipt_footer) }}</textarea>
            @error('receipt_footer')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="btn-primary">Save settings</button>
    </form>

    <div class="space-y-6">
        <div class="card p-6">
            <h2 class="font-bold text-slate-900 text-lg mb-1">Payout bank account</h2>
            <p class="text-sm text-slate-600 mb-4">Where your share of every payment is sent.</p>
            <dl class="grid grid-cols-3 gap-y-2 text-sm">
                <dt class="text-slate-500">Bank</dt><dd class="col-span-2 font-semibold">{{ $school->bank ?? '—' }}</dd>
                <dt class="text-slate-500">Account number</dt><dd class="col-span-2 font-mono font-semibold">{{ $school->account_number ? str_repeat('•', max(strlen($school->account_number) - 4, 0)).substr($school->account_number, -4) : '—' }}</dd>
                <dt class="text-slate-500">Account name</dt><dd class="col-span-2 font-semibold">{{ $school->account_name ?? '—' }}</dd>
            </dl>
        </div>

        <form method="POST" action="{{ route('school.settings.bank', ['school' => $school->slug]) }}" class="card p-6 space-y-4 border-amber-200" id="bankForm">
            @csrf
            @method('PUT')
            <h2 class="font-bold text-slate-900 text-lg">Change payout account</h2>
            <p class="text-sm text-slate-600">The new account is verified with your bank before it is saved, you must confirm your admin password, and a notice is emailed to {{ $school->email }}.</p>
            <div>
                <label for="bank" class="label">Bank</label>
                <select id="bank" name="bank" class="input" required>
                    <option value="">Loading banks…</option>
                </select>
                <input type="hidden" id="bank_code" name="bank_code" value="{{ old('bank_code') }}">
                @error('bank_code')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="account_number" class="label">Account number (NUBAN)</label>
                <input id="account_number" name="account_number" value="{{ old('account_number') }}" class="input font-mono" inputmode="numeric" pattern="\d{10}" maxlength="10" required autocomplete="off">
                <p id="resolvedName" class="text-sm mt-1 font-semibold text-slate-700"></p>
                @error('account_number')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="current_password" class="label">Confirm admin password</label>
                <input id="current_password" name="current_password" type="password" class="input" required autocomplete="current-password">
                @error('current_password')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="btn bg-amber-500 hover:bg-amber-400 text-white" onclick="return confirm('Change the account that receives all future payouts?')">Verify and change account</button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
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

    function syncCode() {
        const opt = bankSelect.options[bankSelect.selectedIndex];
        bankCode.value = opt && opt.dataset.code ? opt.dataset.code : '';
        preview();
    }
    async function preview() {
        resolved.textContent = '';
        if (acct.value.length !== 10 || !bankCode.value) return;
        resolved.textContent = 'Checking…';
        try {
            const r = await fetch(`/api/resolve-account?account_number=${acct.value}&bank_code=${bankCode.value}`);
            const d = await r.json();
            resolved.textContent = d.ok && d.account_name ? '✓ ' + d.account_name : (d.error || 'Could not verify this account');
        } catch (e) { resolved.textContent = 'Could not verify this account'; }
    }
    bankSelect.addEventListener('change', syncCode);
    acct.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 10); preview(); });
})();
</script>
@endpush
