<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        // Defaults for a page that sets no title / meta_description (the homepage),
        // defined once for the <title>, the description and the share card.
        $brand = trim(view('marketing.partials.brand-name')->render());
        $defaultTitle = $brand.' — School fees, collected without the stress';
        $defaultDescription = $brand.' gives parents a simple way to pay school fees online while the school tracks every payment, receipt and payout in one place.';
    @endphp
    <title>
        @hasSection('title')
            @yield('title')
        @else
            {{ $defaultTitle }}
        @endif
    </title>
    <meta name="description" content="@hasSection('meta_description') @yield('meta_description') @else {{ $defaultDescription }} @endif">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    {{-- Social sharing (Open Graph, X). Opt-in with @section('share', 'on'): home,
         contact and registration. Sign-in, password and error pages share this layout
         and must not become share cards. The title and description are the page's own,
         flattened to plain text: section content is HTML (titles span lines, and
         @section('x', '…') values arrive escaped). No og:url, twitter:url or canonical
         until the production domain is confirmed; asset() uses the requesting host. --}}
    @hasSection('share')
        @php
            $plain = fn (string $html) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $shareTitle = $plain($__env->yieldContent('title', $defaultTitle));
            $shareDescription = $plain($__env->yieldContent('meta_description', $defaultDescription));
            $shareImage = asset('images/feyra-og.png');
            $shareImageAlt = $brand.' — School fees. Collected without the stress.';
        @endphp
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $brand }}">
        <meta property="og:title" content="{{ $shareTitle }}">
        <meta property="og:description" content="{{ $shareDescription }}">
        <meta property="og:image" content="{{ $shareImage }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ $shareImageAlt }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $shareTitle }}">
        <meta name="twitter:description" content="{{ $shareDescription }}">
        <meta name="twitter:image" content="{{ $shareImage }}">
        <meta name="twitter:image:alt" content="{{ $shareImageAlt }}">
    @endif

    @include('marketing.partials.head-tokens')
    @stack('head')
</head>
<body class="min-h-screen flex flex-col">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] btn-obsidian btn-sm">Skip to content</a>

    {{-- Pages may override the header/footer (e.g. a slim header on the registration
         page); the homepage uses the defaults. --}}
    @hasSection('nav')
        @yield('nav')
    @else
        @include('marketing.partials.nav')
    @endif

    <main id="main" class="flex-1">
        @yield('content')
    </main>

    @hasSection('footer')
        @yield('footer')
    @else
        @include('marketing.partials.footer')
    @endif

    @stack('scripts')
</body>
</html>
