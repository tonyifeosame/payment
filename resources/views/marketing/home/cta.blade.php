<section class="bg-white" aria-labelledby="cta-heading">
    <div class="container-x py-12 sm:py-16 lg:py-24">
        <div class="relative overflow-hidden rounded-4xl bg-brand-violet px-6 py-14 text-white sm:px-10 sm:py-16 lg:px-16 lg:py-20">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -right-24 -top-32 h-72 w-72 bg-brand-iris rounded-[55%_45%_50%_50%/50%_55%_45%_50%]"></div>
                <div class="absolute -bottom-36 left-1/3 h-64 w-80 bg-brand-iris rounded-[45%_55%_45%_55%/55%_45%_55%_45%]"></div>
            </div>

            <div class="relative grid gap-8 lg:grid-cols-12 lg:items-center">
                <div class="lg:col-span-8">
                    <h2 id="cta-heading" class="font-display text-3xl font-extrabold leading-[1.1] tracking-tight sm:text-4xl lg:text-5xl">Ready to simplify school fee collection?</h2>
                    <p class="mt-4 max-w-2xl text-lg text-white/85">Give your school a simpler way to collect payments and keep records organized.</p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row lg:col-span-4 lg:flex-col lg:items-stretch xl:flex-row xl:justify-end">
                    <a href="{{ route('registration.create') }}" class="btn-zest">Get started</a>
                    <a href="{{ route('contact.show') }}" class="btn-outline-light">Talk to us</a>
                </div>
            </div>
        </div>
    </div>
</section>
