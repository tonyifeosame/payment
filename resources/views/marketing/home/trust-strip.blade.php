{{-- Factual product capabilities only — no counts, volumes or customer claims. --}}
<section class="border-b border-brand-fog bg-brand-fog" aria-label="What @include('marketing.partials.brand-name') does">
    <div class="container-x py-8 sm:py-10">
        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
            @foreach([
                'Secure online payments',
                'Automatic receipts',
                'Real-time payment records',
                'School payout tracking',
            ] as $item)
                <li class="flex items-center gap-3 text-sm font-semibold text-brand-obsidian sm:text-base">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-violet text-white">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
                    </span>
                    {{ $item }}
                </li>
            @endforeach
        </ul>
    </div>
</section>
