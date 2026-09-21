{{--
    CSS-only representation of the parent-facing payment page (/s/{school}/payment):
    student search → selected student → fee → summary → pay. Demonstration data only;
    the fee breakdown mirrors the real page (fee amount + service fee = total).
--}}
@php
    $now = now();
    $sessionStart = $now->month >= 9 ? $now->year : $now->year - 1;
    $termLabel = match (true) {
        $now->month >= 9 => 'First Term',
        $now->month >= 5 => 'Third Term',
        default => 'Second Term',
    };
    $feeLabel = 'Tuition · '.$termLabel.' '.$sessionStart.'/'.($sessionStart + 1);
@endphp
<div class="mx-auto w-full max-w-sm">
    <div role="img"
         aria-label="Illustration of the @include('marketing.partials.brand-name') payment page a parent sees: a selected student, a chosen fee, a payment summary totalling ₦51,250, and a pay button, using demonstration data."
         class="overflow-hidden rounded-4xl border border-brand-obsidian/10 bg-brand-obsidian p-1.5 shadow-[0_30px_80px_-20px_rgba(18,18,23,0.45)]">
        <div aria-hidden="true" class="rounded-[26px] bg-brand-fog text-brand-obsidian">
            {{-- Page header --}}
            <div class="flex items-center justify-between rounded-t-[26px] bg-white px-4 py-3">
                <span class="inline-flex items-center gap-2 font-display text-base font-bold">
                    @include('marketing.partials.logo-mark', ['size' => 26])
                    @include('marketing.partials.brand-name')
                </span>
                <span class="inline-flex items-center gap-1 text-[11px] font-medium text-brand-slate">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                    Secure payment
                </span>
            </div>

            <div class="space-y-3 p-4">
                {{-- School --}}
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-violet font-display text-sm font-bold text-white">D</span>
                        <div>
                            <p class="font-display text-base font-bold leading-tight">Demo School</p>
                            <p class="text-xs text-brand-slate">School fees payment</p>
                        </div>
                    </div>
                    <span class="rounded-full bg-brand-zest px-2.5 py-0.5 text-[11px] font-semibold text-brand-obsidian">Demo data</span>
                </div>

                {{-- Student --}}
                <div class="rounded-2xl bg-white p-3.5">
                    <p class="text-sm font-semibold">Find your student</p>
                    <div class="mt-2 flex items-center gap-2 rounded-xl border border-brand-ash px-3 py-2 text-sm text-brand-slate/70">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/></svg>
                        Type student name…
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3 rounded-xl bg-brand-violet/10 p-3">
                        <div class="flex items-center gap-3">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-violet/20 text-xs font-bold text-brand-violet">TB</span>
                            <div>
                                <p class="text-sm font-semibold leading-tight">Tunde Bakare</p>
                                <p class="text-[11px] text-brand-slate">JSS 2 · Admission ADM-••••482</p>
                            </div>
                        </div>
                        <svg class="h-4 w-4 text-brand-slate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </div>
                </div>

                {{-- Fee --}}
                <div class="rounded-2xl bg-white p-3.5">
                    <p class="text-sm font-semibold">Select fee</p>
                    <div class="mt-2 flex items-center justify-between gap-2 rounded-xl border border-brand-ash px-3 py-2.5 text-sm">
                        <span class="truncate font-medium">{{ $feeLabel }}</span>
                        <span class="flex shrink-0 items-center gap-2 tabular-nums">
                            ₦50,000
                            <svg class="h-4 w-4 text-brand-slate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                        </span>
                    </div>
                </div>

                {{-- Summary --}}
                <div class="rounded-2xl bg-white p-3.5">
                    <p class="text-sm font-semibold">Payment summary</p>
                    <dl class="mt-2 space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-brand-slate">Fee amount</dt><dd class="tabular-nums">₦50,000</dd></div>
                        <div class="flex justify-between"><dt class="text-brand-slate">Service fee</dt><dd class="tabular-nums">₦1,250</dd></div>
                        <div class="flex justify-between border-t border-brand-fog pt-2 font-display text-base font-bold"><dt>Total</dt><dd class="tabular-nums">₦51,250</dd></div>
                    </dl>
                </div>

                {{-- Pay --}}
                <div class="flex min-h-[48px] items-center justify-center gap-2 rounded-xl bg-brand-violet font-semibold text-white">
                    Pay ₦51,250
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </div>
                <p class="text-center text-[11px] text-brand-slate">Payments are processed by Paystack.</p>
            </div>
        </div>
    </div>
</div>
