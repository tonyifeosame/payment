<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') · {{ $school->name }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    {{-- Installable admin app ("FEYRA Admin"). Admin layout only: the marketing and
         payment layouts never link this manifest, so public pages are not installable.
         No service worker is registered anywhere — nothing is cached. --}}
    <link rel="manifest" href="{{ route('admin.manifest') }}">
    <meta name="application-name" content="FEYRA Admin">
    <meta name="theme-color" content="#FFFFFF">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="FEYRA Admin">
    <link rel="apple-touch-icon" href="{{ asset('icons/admin-192.png') }}">
    @include('marketing.partials.head-tokens')
    <style type="text/tailwindcss">
        {{-- Admin-only components. Palette, fonts and the btn-*/field-* primitives come
             from head-tokens; nothing is redefined here. The legacy class names
             (.card .btn-primary .input .th …) are kept so pages not yet redesigned
             render on the new system without markup changes. --}}
        @layer components {
            /* Sidebar */
            .side-link { @apply flex min-h-[48px] items-center gap-3 rounded-xl px-3 text-[15px] font-medium text-brand-obsidian hover:bg-brand-fog focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 transition-colors; }
            .side-link svg { @apply h-5 w-5 shrink-0 text-brand-slate; }
            .side-link.is-active { @apply relative bg-brand-violet/10 text-brand-violet font-semibold; }
            .side-link.is-active svg { @apply text-brand-violet; }
            .side-link.is-active::before { content: ''; @apply absolute left-0 top-3 bottom-3 w-[3px] rounded-full bg-brand-violet; }
            .side-group { @apply px-3 pt-5 pb-1.5 text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-slate; }

            /* Surfaces */
            .card { @apply rounded-2xl border border-brand-ash/60 bg-white; }

            /* Legacy button aliases → FEYRA buttons (48px, no gradients). */
            .btn-primary { @apply btn-obsidian; }
            .btn-secondary { @apply btn-outline; }
            .btn-danger { @apply btn border border-red-200 bg-white text-red-700 hover:border-red-600 hover:bg-red-50 focus-visible:ring-red-500/20; }

            /* Legacy form aliases → FEYRA fields. */
            .label { @apply field-label mb-2; }
            .input { @apply block w-full min-h-[48px] rounded-xl border border-brand-ash bg-white px-4 text-base text-brand-obsidian placeholder:text-brand-slate/60 focus:border-brand-violet focus:outline-none focus:ring-4 focus:ring-brand-violet/20; }
            select.input { @apply pr-10; }
            textarea.input { @apply py-3; }

            /* Tables */
            .th { @apply px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate; }
            .td { @apply px-4 py-3.5 text-sm text-brand-obsidian align-top; }

            /* Badges */
            .badge { @apply inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold; }
        }
    </style>
    @stack('head')
</head>
<body class="min-h-screen bg-brand-fog text-brand-obsidian">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] btn-obsidian btn-sm">Skip to content</a>

    {{-- Mobile top bar (<1024px). --}}
    <header class="sticky top-0 z-40 flex h-14 items-center gap-3 border-b border-brand-ash/60 bg-white px-4 lg:hidden">
        <button type="button" id="adminMenuToggle"
                class="-ml-2 inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-brand-obsidian hover:bg-brand-fog focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30"
                aria-expanded="false" aria-controls="adminSidebar" aria-label="Open menu">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
        <span class="inline-flex min-w-0 items-center gap-2.5">
            @include('marketing.partials.logo-mark', ['size' => 28])
            <span class="truncate font-display text-base font-bold">{{ $school->name }}</span>
        </span>
    </header>

    {{-- Drawer backdrop (mobile only). --}}
    <div id="adminBackdrop" class="fixed inset-0 z-40 hidden bg-brand-obsidian/40 lg:hidden"></div>

    <div class="lg:flex lg:min-h-screen">
        {{-- One sidebar: off-canvas drawer below lg, sticky column at lg and up. --}}
        @include('admin._sidebar')

        <main id="main" class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
            <div class="mx-auto max-w-6xl">
                @hasSection('heading')
                    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between lg:mb-8">
                        <div class="min-w-0">
                            @hasSection('eyebrow')<p class="eyebrow-violet mb-3">@yield('eyebrow')</p>@endif
                            <h1 class="font-display text-[28px] font-extrabold leading-tight tracking-tight sm:text-[32px]">@yield('heading')</h1>
                            @hasSection('subheading')<p class="mt-1.5 text-base text-brand-slate">@yield('subheading')</p>@endif
                        </div>
                        @hasSection('actions')
                            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center md:justify-end [&>a]:w-full [&>a]:sm:w-auto [&>button]:w-full [&>button]:sm:w-auto">@yield('actions')</div>
                        @endif
                    </div>
                @endif

                @include('admin._alert', ['inlineErrors' => View::hasSection('inline-errors')])

                @yield('content')
            </div>
        </main>
    </div>

    <x-admin.confirm />

    <script>
    (function () {
        // Mobile drawer. The sidebar element is shared with desktop, where it is a
        // plain sticky column and this script never changes its state.
        var toggle = document.getElementById('adminMenuToggle');
        var sidebar = document.getElementById('adminSidebar');
        var backdrop = document.getElementById('adminBackdrop');
        var close = document.getElementById('adminMenuClose');
        var desktop = window.matchMedia('(min-width: 1024px)');
        if (!toggle || !sidebar) return;

        function setOpen(open) {
            sidebar.classList.toggle('is-open', open);
            backdrop.classList.toggle('hidden', !open);
            document.body.classList.toggle('overflow-hidden', open && !desktop.matches);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            if (open) {
                // The drawer becomes focusable once its visibility transition has started.
                setTimeout(function () { (close || sidebar.querySelector('a, button')).focus(); }, 220);
            }
        }
        toggle.addEventListener('click', function () { setOpen(toggle.getAttribute('aria-expanded') !== 'true'); });
        backdrop.addEventListener('click', function () { setOpen(false); });
        if (close) close.addEventListener('click', function () { setOpen(false); toggle.focus(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') { setOpen(false); toggle.focus(); }
        });
        desktop.addEventListener('change', function (mq) { if (mq.matches) setOpen(false); });
    })();

    (function () {
        // "Install app": shown only when Chrome says the app is installable
        // (beforeinstallprompt) and the admin has not dismissed it in the last 30
        // days; hidden again once installed. Nothing is stored except that choice.
        var button = document.getElementById('adminInstallApp');
        if (!button) return;
        var KEY = 'feyra-admin-install-dismissed';
        var deferred = null;
        function dismissedRecently() {
            try { var at = parseInt(localStorage.getItem(KEY) || '0', 10); return at && Date.now() - at < 30 * 24 * 60 * 60 * 1000; } catch (e) { return false; }
        }
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferred = e;
            if (!dismissedRecently() && !window.matchMedia('(display-mode: standalone)').matches) button.hidden = false;
        });
        button.addEventListener('click', function () {
            if (!deferred) return;
            deferred.prompt();
            deferred.userChoice.then(function (choice) {
                if (choice.outcome !== 'accepted') { try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {} }
                button.hidden = true;
                deferred = null;
            });
        });
        window.addEventListener('appinstalled', function () { button.hidden = true; deferred = null; });
    })();
    </script>
    @stack('scripts')
</body>
</html>
