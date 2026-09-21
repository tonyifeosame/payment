@php
    $benefits = [
        ['Know who has paid', 'Every payment is tied to a named student and term.'],
        ['See pending and failed payments', 'Incomplete payments are counted and listed separately.'],
        ['Find student records quickly', 'Search the roster by name or admission number and open a student\'s payment history.'],
        ['Track every transaction', 'Filter by status, category, session, term or date.'],
        ['Generate receipts automatically', 'On screen, by email and as a PDF — without lifting a finger.'],
        ['Monitor payouts', 'See what has been paid to your bank, what is on the way, and what needs attention.'],
        ['Reduce manual reconciliation', 'Amounts are verified against Paystack before a payment is recorded as successful.'],
    ];
@endphp
<section id="for-schools" class="scroll-mt-24 bg-brand-fog" aria-labelledby="schools-heading">
    <div class="container-x py-20 sm:py-24 lg:py-32">
        <div class="grid gap-12 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-5">
                <span class="eyebrow-violet">For school administrators</span>
                <h2 id="schools-heading" class="section-title">Built for the people <span class="text-brand-iris">running the school.</span></h2>
                <p class="section-lead">Less chasing, less cross-checking. The records you need are already there when a parent pays.</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('registration.create') }}" class="btn-obsidian">Get started</a>
                    <a href="{{ route('contact.show') }}" class="btn-outline">Talk to us</a>
                </div>
            </div>

            <div class="lg:col-span-7">
                <ul class="grid gap-3 rounded-4xl bg-white p-6 shadow-sm sm:grid-cols-2 sm:p-8">
                    @foreach($benefits as [$title, $body])
                        <li class="flex gap-3 rounded-2xl p-3">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-violet/10 text-brand-violet">
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
        </div>
    </div>
</section>
