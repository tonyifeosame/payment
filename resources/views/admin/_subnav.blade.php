{{-- Secondary navigation inside one primary sidebar section. The admin sidebar has five entries
     (Dashboard, Students, Fees, Payments, Settings); the pages that used to be separate sidebar
     entries are reached from here instead. Usage: @include('admin._subnav', ['section' => 'fees']) --}}
@php
    $subnavParams = ['school' => $school->slug];
    [$subnavLabel, $subnavItems] = match ($section) {
        'students' => ['Students', [
            ['Students', route('school.students.index', $subnavParams), ['school.students.index', 'school.students.create', 'school.students.show', 'school.students.edit']],
            ['Classes', route('school.students.classes.index', $subnavParams), 'school.students.classes.*'],
            ['Promote students', route('school.students.promotion.index', $subnavParams), 'school.students.promotion.*'],
        ]],
        'fees' => ['Fees', [
            ['Fees', route('school.subcategories.index', $subnavParams), 'school.subcategories.*'],
            ['Categories', route('school.categories.index', $subnavParams), 'school.categories.*'],
        ]],
        'payments' => ['Payments', [
            ['Payment history', route('school.transactions.index', $subnavParams), 'school.transactions.*'],
            ['Payouts to your bank', route('school.payouts.index', $subnavParams), 'school.payouts.*'],
        ]],
        'settings' => ['Settings', [
            ['School settings', route('school.settings.edit', $subnavParams), 'school.settings.*'],
            ['Share payment link', route('school.share.index', $subnavParams), 'school.share.*'],
        ]],
    };
@endphp
<nav class="mb-6 flex flex-wrap gap-2" aria-label="{{ $subnavLabel }} pages">
    @foreach($subnavItems as [$subnavItemLabel, $subnavItemHref, $subnavItemPattern])
        @php $subnavActive = request()->routeIs(...(array) $subnavItemPattern); @endphp
        <a href="{{ $subnavItemHref }}"
           class="inline-flex min-h-[48px] items-center rounded-full border px-4 text-sm font-semibold focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 {{ $subnavActive ? 'border-brand-obsidian bg-brand-obsidian text-white' : 'border-brand-ash/60 bg-white text-brand-obsidian hover:border-brand-obsidian' }}"
           @if($subnavActive) aria-current="page" @endif>{{ $subnavItemLabel }}</a>
    @endforeach
</nav>
