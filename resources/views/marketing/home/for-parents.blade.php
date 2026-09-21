@php
    $parentSteps = ['Find your school', 'Select your student', 'Choose the fee', 'Pay securely', 'Get your receipt'];
@endphp
<section class="bg-white" aria-labelledby="parents-heading">
    <div class="container-x py-20 sm:py-24 lg:py-32">
        <div class="mx-auto max-w-3xl text-center">
            <span class="eyebrow-violet">For parents</span>
            <h2 id="parents-heading" class="section-title">A simpler way for <span class="text-brand-iris">parents to pay.</span></h2>
            <p class="section-lead mx-auto">Parents don't need to understand the school's accounting. They open the school's payment link, pick their child, and pay.</p>
        </div>

        {{-- Step chain: vertical on phones, horizontal from sm up. --}}
        <ol class="mx-auto mt-12 flex max-w-6xl flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-center sm:gap-y-3 lg:mt-14 xl:flex-nowrap">
            @foreach($parentSteps as $i => $step)
                <li class="flex items-center gap-2">
                    <span class="flex min-h-[48px] w-full items-center gap-3 whitespace-nowrap rounded-2xl border border-brand-ash px-4 py-3 text-sm font-semibold sm:w-auto">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-violet text-xs font-bold text-white">{{ $i + 1 }}</span>
                        {{ $step }}
                    </span>
                    @unless($loop->last)
                        <svg class="hidden h-5 w-5 shrink-0 text-brand-ash sm:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    @endunless
                </li>
            @endforeach
        </ol>

        {{-- Find your school: existing behaviour, preserved. Builds /s/{slug}/payment from the
             typed school name and navigates there. --}}
        <div class="mx-auto mt-12 max-w-3xl rounded-4xl bg-brand-fog p-6 sm:p-8 lg:mt-16">
            <div class="flex items-start gap-4">
                <span class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white text-brand-violet shadow-sm sm:flex">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21V7l8-4 8 4v14M4 21h16M9 21v-5h6v5M9 11h.01M15 11h.01M12 11h.01"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="font-display text-xl font-bold tracking-tight">Find your school</h3>
                    <p class="mt-1 text-sm text-brand-slate">Enter your school's name to go to its payment page.</p>

                    <form id="findSchoolForm" class="mt-5 flex flex-col gap-3 sm:flex-row" action="#" novalidate>
                        <label for="schoolSlug" class="sr-only">School name or link name</label>
                        <input id="schoolSlug" type="text" autocomplete="off" placeholder="e.g. Life School or life-school"
                               class="min-h-[48px] w-full flex-1 rounded-xl border border-brand-ash bg-white px-4 text-base text-brand-obsidian placeholder:text-brand-slate/70 focus:border-brand-violet focus:outline-none focus:ring-4 focus:ring-brand-violet/20">
                        <button id="goSchool" type="submit" class="btn-obsidian sm:shrink-0">Go to payment page</button>
                    </form>
                    <p class="mt-3 text-xs text-brand-slate">Your school's payment link looks like <code class="rounded bg-white px-1.5 py-0.5 font-mono text-[11px] text-brand-violet">/s/your-school/payment</code>. Ask the school if you're not sure of the name.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    var form = document.getElementById('findSchoolForm');
    var input = document.getElementById('schoolSlug');
    if (!form || !input) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var raw = (input.value || '').trim();
        if (!raw) { input.focus(); return; }
        // Same slug rule as before: lowercase, strip anything but a-z 0-9 - and spaces, spaces -> hyphens.
        var slug = raw.toLowerCase().replace(/[^a-z0-9\-\s]/g, '').replace(/\s+/g, '-');
        window.location.href = '/s/' + encodeURIComponent(slug) + '/payment';
    });
})();
</script>
