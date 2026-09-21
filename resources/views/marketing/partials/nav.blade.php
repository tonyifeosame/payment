@php
    $navLinks = [
        ['Features', '#features'],
        ['How it works', '#how-it-works'],
        ['For schools', '#for-schools'],
    ];
@endphp
<header class="sticky top-0 z-50 border-b border-brand-fog bg-white/95 backdrop-blur">
    <nav class="container-x flex h-16 items-center justify-between gap-6 sm:h-20" aria-label="Main">
        @include('marketing.partials.logo')

        <ul class="hidden items-center gap-2 lg:flex">
            @foreach($navLinks as [$label, $href])
                <li><a href="{{ $href }}" class="nav-link">{{ $label }}</a></li>
            @endforeach
        </ul>

        <div class="hidden items-center gap-3 lg:flex">
            <a href="{{ route('admin.login') }}" class="btn-outline btn-sm">Sign in</a>
            <a href="{{ route('registration.create') }}" class="btn-obsidian btn-sm">Get started</a>
        </div>

        <button type="button" id="menu-toggle"
                class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-brand-ash text-brand-obsidian hover:border-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 lg:hidden"
                aria-expanded="false" aria-controls="mobile-menu" aria-label="Open menu">
            <svg id="menu-icon-open" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            <svg id="menu-icon-close" class="hidden h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </nav>

    <div id="mobile-menu" class="hidden border-t border-brand-fog bg-white lg:hidden">
        <div class="container-x flex flex-col gap-1 py-4">
            @foreach($navLinks as [$label, $href])
                <a href="{{ $href }}" class="nav-link min-h-[44px] text-base" data-menu-link>{{ $label }}</a>
            @endforeach
            <div class="mt-3 flex flex-col gap-2 border-t border-brand-fog pt-4">
                <a href="{{ route('admin.login') }}" class="btn-outline w-full">Sign in</a>
                <a href="{{ route('registration.create') }}" class="btn-obsidian w-full">Get started</a>
            </div>
        </div>
    </div>
</header>

<script>
(function () {
    var toggle = document.getElementById('menu-toggle');
    var menu = document.getElementById('mobile-menu');
    var iconOpen = document.getElementById('menu-icon-open');
    var iconClose = document.getElementById('menu-icon-close');
    if (!toggle || !menu) return;

    function setOpen(open) {
        menu.classList.toggle('hidden', !open);
        iconOpen.classList.toggle('hidden', open);
        iconClose.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }

    toggle.addEventListener('click', function () {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });
    // In-page links: close the panel so the target section is visible.
    menu.querySelectorAll('[data-menu-link]').forEach(function (link) {
        link.addEventListener('click', function () { setOpen(false); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            toggle.focus();
        }
    });
    // Reset if the viewport grows past the mobile breakpoint while the menu is open.
    window.matchMedia('(min-width: 1024px)').addEventListener('change', function (mq) {
        if (mq.matches) setOpen(false);
    });
})();
</script>
