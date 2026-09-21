<section class="bg-white" aria-labelledby="showcase-heading">
    <div class="container-x py-20 sm:py-24 lg:py-32">
        <div class="mx-auto max-w-3xl text-center">
            <span class="eyebrow-violet">Your school at a glance</span>
            <h2 id="showcase-heading" class="section-title">Every payment, <span class="text-brand-iris">in one place.</span></h2>
            <p class="section-lead mx-auto">Collections for the term, what is still pending, what has reached your bank, and who paid — on one screen.</p>
        </div>

        <div class="mt-14 lg:mt-16">
            @include('marketing.partials.dashboard-mockup', ['variant' => 'full'])
        </div>

        <ul class="mx-auto mt-12 grid max-w-5xl gap-6 sm:grid-cols-3">
            @foreach([
                ['Collections by term', 'Totals for today, this week, the selected term and all time.'],
                ['Pending and failed, flagged', 'Payments that did not complete are counted separately so you can follow them up.'],
                ['Payouts to your bank', 'Each payout is tracked from initiation until it is paid, on the way, or needs attention.'],
            ] as [$title, $body])
                <li class="flex gap-3">
                    <span class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-violet text-white">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
                    </span>
                    <div>
                        <h3 class="font-display text-base font-bold">{{ $title }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-slate">{{ $body }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>
