{{-- Slim header for focused public pages (sign-in, password reset, contact): logo plus
     one action. The full marketing nav links to homepage sections, which do not exist
     on these pages. --}}
@once
    @push('head')
        {{-- Tint the whole main area, so a short page has no white band above the footer. --}}
        <style type="text/tailwindcss">
            #main { @apply bg-brand-fog/50; }
        </style>
    @endpush
@endonce
<header class="border-b border-brand-fog bg-white">
    <nav class="container-x flex h-16 items-center justify-between gap-6 sm:h-20" aria-label="Main">
        @include('marketing.partials.logo')
        <div class="flex items-center gap-3">
            @isset($prompt)
                <span class="hidden text-sm text-brand-slate sm:inline">{{ $prompt }}</span>
            @endisset
            <a href="{{ $actionHref }}" class="btn-outline btn-sm">{{ $actionLabel }}</a>
        </div>
    </nav>
</header>
