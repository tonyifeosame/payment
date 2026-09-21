<section class="relative overflow-hidden bg-brand-violet text-white" aria-labelledby="hero-heading">
    {{-- Flat Electric Iris shapes: solid colour, no gradients. --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 overflow-hidden">
        <div class="absolute -top-40 right-[-12%] h-[30rem] w-[30rem] bg-brand-iris rounded-[58%_42%_55%_45%/52%_60%_40%_48%]"></div>
        <div class="absolute -bottom-48 -left-32 h-[26rem] w-[34rem] bg-brand-iris rounded-[45%_55%_40%_60%/55%_45%_55%_45%]"></div>
    </div>

    <div class="container-x relative grid gap-12 py-16 sm:py-20 lg:grid-cols-12 lg:items-center lg:gap-10 lg:py-24 xl:py-28">
        <div class="min-w-0 lg:col-span-6 xl:col-span-5">
            <span class="eyebrow bg-white/15 text-white">Online school fee collection</span>
            <h1 id="hero-heading" class="mt-6 font-display text-4xl font-extrabold leading-[1.05] tracking-tight sm:text-5xl lg:text-6xl xl:text-[4.25rem]">
                School fees.<br>
                <span class="text-brand-zest">Collected without the stress.</span>
            </h1>
            <p class="mt-6 max-w-xl text-lg leading-relaxed text-white/85 sm:text-xl">
                Give parents a simple way to pay school fees online while your school tracks every payment in one place.
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('registration.create') }}" class="btn-zest">
                    Get started
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                <a href="#how-it-works" class="btn-outline-light">See how it works</a>
            </div>
        </div>

        <div class="relative min-w-0 lg:col-span-6 xl:col-span-7">
            {{-- Small Lemon Zest accent glyph. --}}
            <svg aria-hidden="true" class="absolute -top-8 right-4 hidden h-10 w-10 text-brand-zest lg:block" viewBox="0 0 40 40" fill="currentColor">
                <path d="M20 2c1.2 8.6 4.4 11.8 13 13-8.6 1.2-11.8 4.4-13 13-1.2-8.6-4.4-11.8-13-13 8.6-1.2 11.8-4.4 13-13z"/>
                <circle cx="33" cy="32" r="3"/>
            </svg>
            @include('marketing.partials.dashboard-mockup', ['variant' => 'compact'])
        </div>
    </div>
</section>
