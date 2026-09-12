<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') · {{ $school->name }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style type="text/tailwindcss">
        .nav-link { @apply px-3 py-2 rounded-lg text-sm font-semibold text-white/80 hover:text-white hover:bg-white/10 transition; }
        .nav-link.active { @apply bg-white/20 text-white; }
        .card { @apply bg-white rounded-2xl shadow border border-slate-200; }
        .btn { @apply inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-bold text-sm transition shadow-sm; }
        .btn-primary { @apply btn bg-gradient-to-r from-blue-600 to-purple-600 text-white hover:from-blue-500 hover:to-purple-500; }
        .btn-secondary { @apply btn bg-slate-100 text-slate-800 hover:bg-slate-200; }
        .btn-danger { @apply btn bg-red-100 text-red-800 hover:bg-red-200; }
        .input { @apply w-full px-3.5 py-2.5 rounded-lg border-2 border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-50 transition font-medium bg-white; }
        .label { @apply block text-sm font-bold text-slate-700 mb-1.5; }
        .th { @apply px-4 py-3 text-left text-xs font-black text-slate-600 uppercase tracking-wider; }
        .td { @apply px-4 py-3 text-sm text-slate-800 align-top; }
        .badge { @apply inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold; }
    </style>
    @stack('head')
</head>
<body class="bg-gradient-to-br from-slate-50 via-blue-50 to-purple-50 min-h-screen text-slate-800">
    @php
        $nav = [
            ['Dashboard', route('school.dashboard', ['school' => $school->slug]), 'school.dashboard'],
            ['Students', route('school.students.index', ['school' => $school->slug]), 'school.students.*'],
            ['Sessions', route('school.sessions.index', ['school' => $school->slug]), 'school.sessions.*'],
            ['Categories', route('school.categories.index', ['school' => $school->slug]), 'school.categories.*'],
            ['Fee Types', route('school.subcategories.index', ['school' => $school->slug]), 'school.subcategories.*'],
            ['Transactions', route('school.transactions.index', ['school' => $school->slug]), 'school.transactions.*'],
            ['Payouts', route('school.payouts.index', ['school' => $school->slug]), 'school.payouts.*'],
            ['Share', route('school.share.index', ['school' => $school->slug]), 'school.share.*'],
            ['Settings', route('school.settings.edit', ['school' => $school->slug]), 'school.settings.*'],
        ];
    @endphp

    <nav class="bg-gradient-to-r from-[#667eea] to-[#764ba2] shadow-xl sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16 gap-4">
                <a href="{{ route('school.dashboard', ['school' => $school->slug]) }}" class="flex items-center gap-3 min-w-0">
                    @if($school->logoUrl())
                        <img src="{{ $school->logoUrl() }}" alt="" class="w-9 h-9 rounded-lg object-cover bg-white/90 flex-shrink-0">
                    @else
                        <div class="w-9 h-9 bg-white/20 rounded-lg flex items-center justify-center text-white font-black flex-shrink-0">{{ mb_substr($school->name, 0, 1) }}</div>
                    @endif
                    <span class="text-white font-bold truncate">{{ $school->name }}</span>
                </a>
                <div class="hidden lg:flex items-center gap-1">
                    @foreach($nav as [$label, $href, $pattern])
                        <a href="{{ $href }}" class="nav-link {{ request()->routeIs($pattern) ? 'active' : '' }}">{{ $label }}</a>
                    @endforeach
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ $school->paymentUrl() }}" target="_blank" rel="noopener" class="hidden sm:inline-flex nav-link">Payment page ↗</a>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="px-3 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-white text-sm font-bold">Logout</button>
                    </form>
                    <button type="button" class="lg:hidden px-3 py-2 rounded-lg bg-white/15 text-white" onclick="document.getElementById('mobileNav').classList.toggle('hidden')" aria-label="Menu">☰</button>
                </div>
            </div>
            <div id="mobileNav" class="hidden lg:hidden pb-3 flex flex-wrap gap-1">
                @foreach($nav as [$label, $href, $pattern])
                    <a href="{{ $href }}" class="nav-link {{ request()->routeIs($pattern) ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @hasSection('heading')
            <div class="mb-6 flex flex-col md:flex-row md:items-end md:justify-between gap-3">
                <div>
                    <h1 class="text-3xl font-black text-slate-900">@yield('heading')</h1>
                    @hasSection('subheading')<p class="text-slate-600 font-medium mt-1">@yield('subheading')</p>@endif
                </div>
                <div class="flex flex-wrap gap-2">@yield('actions')</div>
            </div>
        @endif

        @if(session('success'))
            <div class="mb-6 rounded-xl border-2 border-green-200 bg-green-50 p-4 text-green-800 font-semibold">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-6 rounded-xl border-2 border-red-200 bg-red-50 p-4 text-red-800 font-semibold">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="mb-6 rounded-xl border-2 border-red-200 bg-red-50 p-4 text-red-800">
                <p class="font-bold mb-1">Please fix the following:</p>
                <ul class="list-disc list-inside text-sm space-y-0.5">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
