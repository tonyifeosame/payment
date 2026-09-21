{{-- Brand mark + wordmark, linked to the homepage. The mark lives in partials/logo-mark and
     the wordmark text in partials/brand-name — the single sources of truth for both. --}}
<a href="{{ route('home') }}" class="inline-flex items-center gap-2.5 rounded-lg font-display text-xl font-bold text-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30" aria-label="@include('marketing.partials.brand-name') — home">
    @include('marketing.partials.logo-mark')
    <span>@include('marketing.partials.brand-name')</span>
</a>
