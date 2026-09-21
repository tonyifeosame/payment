{{-- The admin navigation, rendered once. Below lg it is an off-canvas drawer driven by the
     script in layouts/admin; at lg and up it is a sticky 264px column. Routes and labels are
     the existing admin routes — nothing here is new server-side. --}}
@php
    $s = ['school' => $school->slug];
    $navGroups = [
        [null, [
            ['Dashboard', route('school.dashboard', $s), 'school.dashboard', 'M4 13h6V4H4v9zm10 7h6V11h-6v9zM4 20h6v-5H4v5zm10-9h6V4h-6v7z'],
        ]],
        ['School', [
            ['Students', route('school.students.index', $s), 'school.students.*', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM5 20a7 7 0 0114 0'],
            ['Sessions', route('school.sessions.index', $s), 'school.sessions.*', 'M8 3v3M16 3v3M4 9h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z'],
        ]],
        ['Fees & categories', [
            ['Categories', route('school.categories.index', $s), 'school.categories.*', 'M4 6h16M4 12h16M4 18h10'],
            ['Fee Types', route('school.subcategories.index', $s), 'school.subcategories.*', 'M12 4v16m4-12H10a2.5 2.5 0 000 5h4a2.5 2.5 0 010 5H8'],
        ]],
        ['Money', [
            ['Transactions', route('school.transactions.index', $s), 'school.transactions.*', 'M4 8h16M4 16h16M8 4l-4 4 4 4M16 12l4 4-4 4'],
            ['Payouts', route('school.payouts.index', $s), 'school.payouts.*', 'M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2zm3 8h3'],
        ]],
        ['Sharing & setup', [
            ['Share', route('school.share.index', $s), 'school.share.*', 'M8.5 13.5l7-4M8.5 10.5l7 4M18 5a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0zM8 12a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0zm10 7a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z'],
            ['Settings', route('school.settings.edit', $s), 'school.settings.*', 'M12 15a3 3 0 100-6 3 3 0 000 6zm7.4-3a7.4 7.4 0 00-.1-1l2-1.6-2-3.4-2.4 1a7.6 7.6 0 00-1.7-1L14.8 3H9.2l-.4 2.5a7.6 7.6 0 00-1.7 1l-2.4-1-2 3.4L4.7 11a7.4 7.4 0 000 2l-2 1.6 2 3.4 2.4-1a7.6 7.6 0 001.7 1l.4 2.5h5.6l.4-2.5a7.6 7.6 0 001.7-1l2.4 1 2-3.4-2-1.6c.1-.3.1-.7.1-1z'],
        ]],
    ];
@endphp
<aside id="adminSidebar"
       class="invisible fixed inset-y-0 left-0 z-50 flex w-[288px] max-w-[85vw] -translate-x-full flex-col border-r border-brand-ash/60 bg-white transition-[transform,visibility] duration-200 [&.is-open]:visible [&.is-open]:translate-x-0 lg:visible lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:w-[264px] lg:max-w-none lg:translate-x-0 lg:transition-none"
       aria-label="Admin sidebar">
    <div class="flex h-14 items-center justify-between px-4 lg:h-auto lg:px-5 lg:pt-6 lg:pb-2">
        <a href="{{ route('home') }}" class="inline-flex items-center gap-2.5 rounded-lg font-display text-lg font-bold text-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30" aria-label="@include('marketing.partials.brand-name') — home">
            @include('marketing.partials.logo-mark', ['size' => 30])
            <span>@include('marketing.partials.brand-name')</span>
        </a>
        <button type="button" id="adminMenuClose"
                class="-mr-2 inline-flex h-12 w-12 items-center justify-center rounded-xl text-brand-obsidian hover:bg-brand-fog focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 lg:hidden"
                aria-label="Close menu">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>

    {{-- School identity --}}
    <div class="mx-4 mt-2 flex items-center gap-3 rounded-2xl bg-brand-fog p-3 lg:mx-5">
        @if($school->logoUrl())
            <img src="{{ $school->logoUrl() }}" alt="" class="h-10 w-10 shrink-0 rounded-xl border border-brand-ash/60 bg-white object-contain">
        @else
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-violet font-display text-base font-bold text-white" aria-hidden="true">{{ mb_substr($school->name, 0, 1) }}</span>
        @endif
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold">{{ $school->name }}</p>
            <a href="{{ $school->paymentUrl() }}" target="_blank" rel="noopener" class="inline-flex min-h-[24px] items-center gap-1 text-xs font-medium text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 rounded">
                Payment page <span aria-hidden="true">↗</span>
            </a>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto px-4 pb-4 lg:px-5" aria-label="Admin">
        @foreach($navGroups as [$group, $items])
            @if($group)<p class="side-group">{{ $group }}</p>@else<div class="pt-4"></div>@endif
            <ul class="space-y-0.5">
                @foreach($items as [$label, $href, $pattern, $icon])
                    @php $active = request()->routeIs($pattern); @endphp
                    <li>
                        <a href="{{ $href }}" class="side-link {{ $active ? 'is-active' : '' }}" @if($active) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </nav>

    <div class="border-t border-brand-ash/60 p-4 lg:px-5">
        {{-- Revealed by layouts/admin only when the browser offers installation. --}}
        <button type="button" id="adminInstallApp" class="side-link w-full text-left" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14"/></svg>
            Install app
        </button>
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit" class="side-link w-full text-left">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M13 4h6a1 1 0 011 1v14a1 1 0 01-1 1h-6"/></svg>
                Logout
            </button>
        </form>
    </div>
</aside>
