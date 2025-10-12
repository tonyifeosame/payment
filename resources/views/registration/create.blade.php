@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8">
        <h1 class="text-2xl font-bold text-slate-800">School Registration</h1>
        <p class="text-slate-600 mt-1">Provide your school details to generate your payment page.</p>

        @if ($errors->any())
            <div class="mt-4 mb-4 rounded-md border border-red-200 bg-red-50 p-3">
                <p class="font-medium text-red-800 mb-1">There were some problems with your input:</p>
                <ul class="list-disc list-inside text-red-700 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('registration.store') }}" method="POST" class="mt-6 space-y-5">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700" for="name">School Name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="email">School Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700" for="admin_password">Admin Password</label>
                    <input id="admin_password" name="admin_password" type="password" required
                           class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700" for="admin_password_confirmation">Confirm Password</label>
                    <input id="admin_password_confirmation" name="admin_password_confirmation" type="password" required
                           class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                </div>
            </div>
            <div class="flex items-center gap-2">
                <input id="toggle_admin_pw" type="checkbox" class="rounded border-slate-300" />
                <label for="toggle_admin_pw" class="text-sm text-slate-700 select-none">Show passwords</label>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="bank">School Bank</label>
                <select id="bank" name="bank" required
                        class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500 bg-white">
                    <option value="">Loading banks...</option>
                </select>
                <input type="hidden" id="bank_code" name="bank_code" value="{{ old('bank_code') }}" />
                @error('bank_code')<div class="text-sm text-red-600 mt-1">{{ $message }}</div>@enderror
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700" for="account_number">School Account Number</label>
                    <input id="account_number" name="account_number" type="text" value="{{ old('account_number') }}" required
                           class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700" for="account_name">Account Name (auto)</label>
                    <input id="account_name" name="account_name" type="text" value="{{ old('account_name') }}" readonly
                           class="mt-1 w-full rounded-md border-slate-300 bg-slate-50 focus:border-blue-500 focus:ring-blue-500" />
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="address">School Address (optional)</label>
                <input id="address" name="address" type="text" value="{{ old('address') }}"
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
            </div>

            <div class="pt-2 flex items-center gap-3">
                <a href="{{ route('home') }}" class="px-4 py-2 rounded-md border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
                <button type="submit" class="px-5 py-2.5 rounded-md bg-blue-600 text-white hover:bg-blue-700">Register School</button>
                <a href="{{ route('admin.login') }}" class="px-4 py-2 rounded-md border border-slate-300 text-slate-700 hover:bg-slate-50">Admin Login</a>
            </div>
        </form>
        <script>
            (function(){
                const cb = document.getElementById('toggle_admin_pw');
                const pw1 = document.getElementById('admin_password');
                const pw2 = document.getElementById('admin_password_confirmation');
                if (cb && pw1 && pw2) {
                    cb.addEventListener('change', function(){
                        const t = this.checked ? 'text' : 'password';
                        pw1.type = t;
                        pw2.type = t;
                    });
                }
            })();

            // Load banks and wire up auto-resolve
            (function(){
                const bankSelect = document.getElementById('bank');
                const bankCodeEl = document.getElementById('bank_code');
                const acctNumEl = document.getElementById('account_number');
                const acctNameEl = document.getElementById('account_name');

                async function loadBanks(){
                    try {
                        const res = await fetch('{{ route('api.banks') }}');
                        const json = await res.json();
                        if (!json.ok) throw new Error('Failed to load banks');
                        bankSelect.innerHTML = '';

                        // Prioritize likely Nigerian banks (Paystack codes)
                        const popularCodes = ['044','058','057','011','033']; // Access, GTB, Zenith, First Bank, UBA
                        const banks = Array.isArray(json.banks) ? json.banks : [];
                        const byCode = new Map(banks.map(b => [String(b.code), b]));

                        const popular = [];
                        popularCodes.forEach(c => { const b = byCode.get(String(c)); if (b) popular.push(b); });

                        const popularCodesSet = new Set(popular.map(b => String(b.code)));
                        const others = banks.filter(b => !popularCodesSet.has(String(b.code)));

                        // Build Popular group (first 5 likely banks)
                        const optPopular = document.createElement('optgroup');
                        optPopular.label = 'Popular Banks';
                        popular.forEach(b => {
                            const opt = document.createElement('option');
                            opt.value = b.name;
                            opt.dataset.code = b.code;
                            opt.textContent = b.name;
                            optPopular.appendChild(opt);
                        });

                        // Build All Banks group
                        const optAll = document.createElement('optgroup');
                        optAll.label = 'All Banks';
                        others.forEach(b => {
                            const opt = document.createElement('option');
                            opt.value = b.name;
                            opt.dataset.code = b.code;
                            opt.textContent = b.name;
                            optAll.appendChild(opt);
                        });

                        // Placeholder option
                        const placeholder = document.createElement('option');
                        placeholder.value = '';
                        placeholder.textContent = '-- Select Bank --';
                        placeholder.selected = true;
                        placeholder.disabled = true;
                        bankSelect.appendChild(placeholder);
                        if (popular.length) bankSelect.appendChild(optPopular);
                        if (others.length) bankSelect.appendChild(optAll);
                        // Restore old selection if present
                        const oldBank = @json(old('bank'));
                        if (oldBank) {
                            // Find matching option across groups
                            const opts = bankSelect.querySelectorAll('option');
                            for (const o of opts) {
                                if (o.value === oldBank) { bankSelect.value = oldBank; bankCodeEl.value = o.dataset.code || ''; break; }
                            }
                        }
                    } catch (e) {
                        bankSelect.innerHTML = '<option value="">Unable to load banks</option>';
                    }
                }

                async function resolveAccount(){
                    acctNameEl.value = '';
                    const bankCode = bankCodeEl.value;
                    const acct = (acctNumEl.value || '').trim();
                    if (!bankCode || acct.length < 10) return;
                    try {
                        const u = new URL('{{ route('api.resolve-account') }}', window.location.origin);
                        u.searchParams.set('bank_code', bankCode);
                        u.searchParams.set('account_number', acct);
                        const res = await fetch(u.toString());
                        const json = await res.json();
                        if (json.ok && json.account_name) {
                            acctNameEl.value = json.account_name;
                        }
                    } catch (e) { /* ignore */ }
                }

                bankSelect?.addEventListener('change', function(){
                    const sel = this.options[this.selectedIndex];
                    bankCodeEl.value = sel ? (sel.dataset.code || '') : '';
                    resolveAccount();
                });
                acctNumEl?.addEventListener('input', function(){
                    // debounce
                    clearTimeout(this._t);
                    this._t = setTimeout(resolveAccount, 500);
                });

                loadBanks();
            })();
        </script>
    </div>
</div>
@endsection
