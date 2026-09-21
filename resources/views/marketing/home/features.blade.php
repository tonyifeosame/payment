@php
    // Each card maps to a verified capability. Icons are inline SVG paths (24x24 grid).
    $features = [
        [
            'Online fee payments',
            'Parents pay school fees online through Paystack, from any device, using the school\'s own payment link.',
            '<rect x="3" y="6" width="18" height="12" rx="2.5"/><path d="M3 10h18M7 14.5h3"/>',
        ],
        [
            'Student records',
            'Every payment is linked to the right student, class and academic term, so you always know who a payment belongs to.',
            '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        ],
        [
            'Automatic receipts',
            'A successful payment produces a receipt on screen, sends it by email, and offers a branded PDF download.',
            '<path d="M7 3h10v18l-2.5-1.5L12 21l-2.5-1.5L7 21z"/><path d="M10 8h4M10 12h4"/>',
        ],
        [
            'Payment tracking',
            'See successful, pending and failed payments as they happen, and filter transactions by status, category, term or date.',
            '<path d="M4 18V9M10 18V5M16 18v-7M22 18H2"/>',
        ],
        [
            'Payout tracking',
            'Follow each payout to your school\'s bank account through every status — paid, on the way, or needing attention.',
            '<path d="M3 10.5h18M3 10.5l9-6 9 6M5 10.5V18M19 10.5V18M9 14v4M15 14v4M2 21h20"/>',
        ],
        [
            'Collections & exports',
            'Break collections down by category for any term, and export your transactions to a spreadsheet when you need to.',
            '<path d="M12 3v11m0 0l-4-4m4 4l4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        ],
    ];
@endphp
<section id="features" class="scroll-mt-24 bg-white" aria-labelledby="features-heading">
    <div class="container-x py-20 sm:py-24 lg:py-32">
        <div class="mx-auto max-w-3xl text-center">
            <span class="eyebrow-violet">Everything you need</span>
            <h2 id="features-heading" class="section-title">Everything your school needs to <span class="text-brand-iris">collect fees</span></h2>
            <p class="section-lead mx-auto">From the parent's payment to your bank account, every step is recorded in one place.</p>
        </div>

        <ul class="mt-14 grid gap-5 sm:grid-cols-2 lg:mt-16 lg:grid-cols-3 lg:gap-6">
            @foreach($features as [$title, $body, $icon])
                <li class="rounded-3xl bg-brand-fog p-7 lg:p-8">
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white text-brand-violet shadow-sm">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icon !!}</svg>
                    </span>
                    <h3 class="mt-6 font-display text-xl font-bold tracking-tight">{{ $title }}</h3>
                    <p class="mt-2 text-base leading-relaxed text-brand-slate">{{ $body }}</p>
                </li>
            @endforeach
        </ul>
    </div>
</section>
