<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>
        @hasSection('title')
            @yield('title')
        @else
            @include('marketing.partials.brand-name') — School fees, collected without the stress
        @endif
    </title>
    <meta name="description" content="@hasSection('meta_description') @yield('meta_description') @else @include('marketing.partials.brand-name') gives parents a simple way to pay school fees online while the school tracks every payment, receipt and payout in one place. @endif">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

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
