@php
    // The actual parent journey on /s/{school}/payment.
    $steps = [
        ['01', 'Select your student', 'Search the school\'s student list by name and confirm the admission number.',
            '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>'],
        ['02', 'Select the fee', 'Choose the category and fee type for the current session and term.',
            '<path d="M4 6h16M4 12h16M4 18h10"/><circle cx="19" cy="18" r="2"/>'],
        ['03', 'Pay securely', 'Checkout is handled by Paystack. The amount is verified before anything is recorded.',
            '<rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'],
        ['04', 'Receive your receipt', 'A receipt appears on screen, is emailed, and can be downloaded as a PDF.',
            '<path d="M5 12.5l4.5 4.5L19 7"/>'],
    ];
    $today = now()->format('d M');
@endphp
<section id="how-it-works" class="scroll-mt-24 bg-brand-violet/[0.06]" aria-labelledby="flow-heading">
    <div class="container-x py-20 sm:py-24 lg:py-32">
        <div class="mx-auto max-w-3xl text-center">
            <span class="eyebrow-violet">How it works</span>
            <h2 id="flow-heading" class="section-title">Parents pay. <span class="text-brand-iris">Your records update.</span></h2>
            <p class="section-lead mx-auto">Four steps for the parent. Every successful payment shows up in your dashboard.</p>
        </div>

        <ol class="mt-14 grid gap-4 sm:grid-cols-2 lg:mt-16 lg:grid-cols-4 lg:gap-5">
            @foreach($steps as [$num, $title, $body, $icon])
                <li class="relative rounded-3xl bg-white p-7 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-violet/10 text-brand-violet">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg>
                        </span>
                        <span class="font-display text-sm font-bold tracking-[0.15em] text-brand-slate">{{ $num }}</span>
                    </div>
                    <h3 class="mt-6 font-display text-lg font-bold tracking-tight">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-brand-slate">{{ $body }}</p>
                </li>
            @endforeach
        </ol>

        {{-- The product story in two screens: the parent's payment page on the left, the
             school's dashboard record it produces on the right. Stacks payment-first on phones. --}}
        <div class="mx-auto mt-14 max-w-6xl lg:mt-20">
            <div class="grid items-center gap-8 lg:grid-cols-[minmax(0,5fr)_auto_minmax(0,6fr)] lg:gap-6">
                {{-- Parent side --}}
                <div class="min-w-0">
                    <p class="mb-4 text-center text-xs font-semibold uppercase tracking-[0.12em] text-brand-slate">What the parent sees</p>
                    @include('marketing.partials.payment-mockup')
                </div>

                {{-- Connector: down on phones, right on desktop --}}
                <div class="flex justify-center" aria-hidden="true">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-violet text-white shadow-md">
                        <svg class="h-5 w-5 lg:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16m0 0l-6-6m6 6l6-6"/></svg>
                        <svg class="hidden h-5 w-5 lg:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16m0 0l-6-6m6 6l-6 6"/></svg>
                    </span>
                </div>

                {{-- School side --}}
                <div class="min-w-0">
                    <p class="mb-4 text-center text-xs font-semibold uppercase tracking-[0.12em] text-brand-slate">What the school sees</p>
            <div class="rounded-4xl border border-brand-obsidian/10 bg-white p-2 shadow-[0_20px_60px_-24px_rgba(18,18,23,0.35)]">
                <div class="rounded-[26px] bg-brand-fog/60 p-4 sm:p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold">Your dashboard · Recent payments</p>
                        <span class="rounded-full bg-brand-zest px-2.5 py-0.5 text-[11px] font-semibold text-brand-obsidian">Demo data</span>
                    </div>
                    <ul class="mt-3 divide-y divide-brand-fog rounded-2xl bg-white px-4">
                        <li class="-mx-4 flex items-center justify-between gap-3 rounded-t-2xl border-l-4 border-brand-zest bg-brand-zest/10 px-4 py-3">
                            <div class="min-w-0">
                                <p class="flex items-center gap-1.5 text-sm font-semibold"><span class="truncate">Tunde Bakare</span><span class="shrink-0 rounded-full bg-brand-violet px-2 py-0.5 text-[10px] font-semibold text-white">New</span></p>
                                <p class="truncate text-xs text-brand-slate">ADM-0482 · Tuition · receipt emailed</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold text-green-800">Success</span>
                                <div class="text-right">
                                    <p class="text-sm font-bold tabular-nums">₦50,000</p>
                                    <p class="text-[11px] text-brand-slate">{{ $today }}</p>
                                </div>
                            </div>
                        </li>
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">Chinedu Bello</p>
                                <p class="truncate text-xs text-brand-slate">ADM-0387 · Boarding</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold text-green-800">Success</span>
                                <p class="text-sm font-bold tabular-nums">₦95,000</p>
                            </div>
                        </li>
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">Fatima Yusuf</p>
                                <p class="truncate text-xs text-brand-slate">ADM-0290 · Tuition</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">Pending</span>
                                <p class="text-sm font-bold tabular-nums">₦150,000</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
                </div>
            </div>
            <p class="mt-6 text-center text-xs text-brand-slate">Demonstration data for illustration only. The dashboard records the school's share of each payment; the service fee is never paid out.</p>
        </div>
    </div>
</section>
