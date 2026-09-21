@php
    // Only what the implementation actually does. No certifications or compliance claims.
    $points = [
        ['Secure payment processing', 'Card and bank payments are processed by Paystack. Parents pay on Paystack\'s checkout, not on a form we build.',
            '<rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'],
        ['Tenant-isolated school data', 'Each school\'s students, fees, payments and payouts are scoped to that school. One school cannot see another\'s records.',
            '<rect x="3" y="4" width="8" height="16" rx="2"/><rect x="13" y="4" width="8" height="16" rx="2"/>'],
        ['Verified payment callbacks', 'Paystack webhooks are signature-checked, and the paid amount and currency are re-verified before a payment is marked successful.',
            '<path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>'],
        ['Signed receipt links', 'Receipt downloads use signed links, so a receipt cannot be opened by guessing its number.',
            '<path d="M7 3h10v18l-2.5-1.5L12 21l-2.5-1.5L7 21z"/><path d="M10 9h4M10 13h2"/>'],
        ['Protected school administration', 'Management pages require a school admin login. Sign-in attempts and payout bank-detail changes are rate limited.',
            '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/><path d="M17 3l1.5 1.5L21 2"/>'],
        ['Bank account verification', 'Your payout account name is resolved with the bank before it is saved, so payouts go to a confirmed account.',
            '<path d="M3 10.5h18M3 10.5l9-6 9 6M5 10.5V18M19 10.5V18M2 21h20"/><path d="M9.5 15l1.75 1.75L14.5 13.5"/>'],
    ];
@endphp
<section class="bg-brand-fog" aria-labelledby="security-heading">
    <div class="container-x py-20 sm:py-24 lg:py-32">
        <div class="mx-auto max-w-3xl text-center">
            <span class="eyebrow-violet">Built on trust</span>
            <h2 id="security-heading" class="section-title">Built to handle school money <span class="text-brand-iris">carefully.</span></h2>
            <p class="section-lead mx-auto">This is how @include('marketing.partials.brand-name') is put together. No more, no less.</p>
        </div>

        <ul class="mt-14 grid gap-5 sm:grid-cols-2 lg:mt-16 lg:grid-cols-3 lg:gap-6">
            @foreach($points as [$title, $body, $icon])
                <li class="rounded-3xl bg-white p-7 shadow-sm">
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand-violet/10 text-brand-violet">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg>
                    </span>
                    <h3 class="mt-5 font-display text-lg font-bold tracking-tight">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-brand-slate">{{ $body }}</p>
                </li>
            @endforeach
        </ul>
    </div>
</section>
