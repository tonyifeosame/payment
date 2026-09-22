@extends('layouts.admin')

@section('title', 'Promote students')
@section('eyebrow', 'School · Students')
@section('heading', 'Promote students')
@section('subheading', 'Move the roster into the next academic session, one class up your ladder. Nothing changes until you confirm.')
@section('actions')
    <a href="{{ route('school.students.index', ['school' => $school->slug]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        All students
    </a>
@endsection

@section('content')
@php $s = ['school' => $school->slug]; @endphp

{{-- Step 1: which session --}}
<form method="GET" action="{{ route('school.students.promotion.index', $s) }}" class="card p-5 sm:p-6" aria-labelledby="session-heading">
    <h2 id="session-heading" class="font-display text-lg font-bold tracking-tight">1. Academic session</h2>
    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-2xl bg-brand-fog p-4">
            <p class="text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate">Current session</p>
            <p class="mt-1 font-display text-xl font-bold">{{ $current?->name ?? 'Not set' }}</p>
            @unless($current)<p class="mt-1 text-xs text-brand-slate">Set a current term on the Sessions page.</p>@endunless
        </div>
        <div>
            <label for="to_session_id" class="field-label !mt-0">Promoting into</label>
            <select id="to_session_id" name="to_session_id" class="field-input" required>
                <option value="">Choose a session</option>
                @foreach($sessions as $session)
                    <option value="{{ $session->id }}" @selected($to && $to->id === $session->id)>{{ $session->name }}@if($current && $current->id === $session->id) (current)@endif</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <button type="submit" class="btn-outline w-full sm:w-auto">Show preview</button>
        </div>
    </div>
    @if($sessions->count() < 2)
        <p class="mt-4 text-sm text-brand-slate">Create the session you are promoting into first — <a href="{{ route('school.sessions.index', $s) }}" class="font-semibold text-brand-violet underline underline-offset-2">Sessions</a>.</p>
    @endif
    @unless($hasLadder)
        <p class="mt-4 text-sm text-brand-slate">Promotion follows your class ladder, which is empty. <a href="{{ route('school.students.classes.index', $s) }}" class="font-semibold text-brand-violet underline underline-offset-2">Set up classes</a> first.</p>
    @endunless
</form>

@if($to && $preview)
    @php $rows = $preview['rows']; $groups = $preview['groups']; @endphp

    {{-- Step 2: preview by class --}}
    <section class="card mt-6 p-5 sm:p-6" aria-labelledby="preview-heading">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="preview-heading" class="font-display text-lg font-bold tracking-tight">2. What will happen</h2>
            <p class="text-sm text-brand-slate">{{ $current?->name ?? 'Current' }} → <span class="font-semibold text-brand-obsidian">{{ $to->name }}</span></p>
        </div>
        @if($groups->isEmpty())
            <p class="mt-3 text-sm text-brand-slate">No active students are waiting to be promoted into {{ $to->name }}.</p>
        @else
            <div class="mt-4 overflow-hidden rounded-2xl border border-brand-ash/60">
                <table class="w-full text-sm">
                    <caption class="sr-only">Students per class and the class they move to</caption>
                    <thead class="bg-brand-fog"><tr>
                        <th scope="col" class="th">Current class</th>
                        <th scope="col" class="th w-px whitespace-nowrap text-right">Students</th>
                        <th scope="col" class="th">Next class</th>
                    </tr></thead>
                    <tbody class="divide-y divide-brand-fog">
                        @foreach($groups as $g)
                            <tr>
                                <td class="td font-semibold">{{ $g['from']->name }}</td>
                                <td class="td text-right tabular-nums">{{ $g['count'] }}</td>
                                <td class="td">
                                    <span class="sr-only">moves to </span>
                                    @if($g['to']){{ $g['to']->name }}@else<span class="font-semibold">Graduated</span> <span class="text-xs text-brand-slate">(last class in your ladder)</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if($preview['already'] > 0)
            <p class="mt-3 text-sm text-brand-slate">{{ $preview['already'] }} {{ Str::plural('student', $preview['already']) }} already promoted into {{ $to->name }} {{ $preview['already'] === 1 ? 'is' : 'are' }} not listed — a student is promoted into a session once.</p>
        @endif
        @if($preview['unassigned'] > 0)
            <p class="mt-3 text-sm text-brand-slate">{{ $preview['unassigned'] }} active {{ Str::plural('student', $preview['unassigned']) }} without a class on your ladder cannot be promoted — <a href="{{ route('school.students.classes.index', $s) }}" class="font-semibold text-brand-violet underline underline-offset-2">assign their class</a> first.</p>
        @endif
    </section>

    {{-- Step 3: choose students --}}
    @if($rows->isNotEmpty())
        <form method="POST" action="{{ route('school.students.promotion.review', $s) }}" class="card mt-6 p-5 sm:p-6" aria-labelledby="select-heading" id="promotionForm">
            @csrf
            <input type="hidden" name="to_session_id" value="{{ $to->id }}">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="select-heading" class="font-display text-lg font-bold tracking-tight">3. Who moves</h2>
                <p class="text-sm text-brand-slate"><span id="selectedCount" class="font-semibold text-brand-obsidian">{{ $rows->count() }}</span> of {{ $rows->count() }} selected</p>
            </div>
            <p class="mt-1 text-sm text-brand-slate">Untick anyone who is repeating a class, transferring or leaving. They stay exactly as they are.</p>

            <div class="mt-5 space-y-5">
                @foreach($groups as $g)
                    <fieldset class="rounded-2xl border border-brand-ash/60">
                        <legend class="ml-4 px-2 font-display text-base font-bold">{{ $g['from']->name }} <span class="text-brand-slate" aria-hidden="true">→</span><span class="sr-only">to</span> {{ $g['to']?->name ?? 'Graduated' }}</legend>
                        <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-1">
                            <p class="text-xs text-brand-slate">{{ $g['count'] }} {{ Str::plural('student', $g['count']) }}</p>
                            <button type="button" class="js-toggle-group inline-flex min-h-[48px] items-center rounded-xl px-3 text-sm font-semibold text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30" data-group="{{ $g['from']->id }}" hidden>Untick all in {{ $g['from']->name }}</button>
                        </div>
                        <ul class="divide-y divide-brand-fog px-2 pb-2">
                            @foreach($rows->where('from.id', $g['from']->id) as $row)
                                @php $st = $row['student']; @endphp
                                <li>
                                    <label class="flex min-h-[56px] cursor-pointer items-center gap-3 rounded-xl px-2 py-2 hover:bg-brand-fog/60">
                                        <input type="checkbox" name="students[]" value="{{ $st->id }}" class="h-5 w-5 shrink-0 rounded border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" data-group="{{ $g['from']->id }}" checked>
                                        <input type="hidden" name="from[{{ $st->id }}]" value="{{ $row['from']->id }}">
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium">{{ $st->full_name }}</span>
                                            <span class="block text-xs text-brand-slate"><span class="font-mono">{{ $st->admission_number }}</span> · {{ $row['from']->name }} <span aria-hidden="true">→</span><span class="sr-only">to</span> {{ $row['to']?->name ?? 'Graduated' }}</span>
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </fieldset>
                @endforeach
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                <a href="{{ route('school.students.index', $s) }}" class="btn-outline">Cancel</a>
                <button type="submit" class="btn-obsidian">Review promotion</button>
            </div>
        </form>
    @endif
@endif

{{-- Recent runs --}}
@if($recent->isNotEmpty())
    <section class="card mt-6 p-5 sm:p-6" aria-labelledby="recent-heading">
        <h2 id="recent-heading" class="font-display text-lg font-bold tracking-tight">Recent promotions</h2>
        <ul class="mt-3 divide-y divide-brand-fog text-sm">
            @foreach($recent as $run)
                <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-3">
                    <span><span class="font-semibold">{{ $run->fromSession?->name ?? '—' }} → {{ $run->toSession?->name }}</span> <span class="text-brand-slate">· {{ $run->promoted_count }} promoted, {{ $run->graduated_count }} graduated, {{ $run->excluded_count }} excluded</span></span>
                    @php $runAt = \App\Support\BusinessTime::display($run->created_at); @endphp
                    <time datetime="{{ $runAt->toIso8601String() }}" class="text-xs text-brand-slate">{{ $runAt->format('d M Y, H:i') }}</time>
                </li>
            @endforeach
        </ul>
    </section>
@endif
@endsection

@push('scripts')
<script>
(function () {
    // Progressive enhancement only: the counts and the per-class untick buttons.
    // Without JavaScript every checkbox still submits and the server recounts.
    var form = document.getElementById('promotionForm');
    if (!form) return;
    var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="students[]"]'));
    var selected = document.getElementById('selectedCount');
    function refresh() {
        var on = boxes.filter(function (b) { return b.checked; }).length;
        selected.textContent = on;
        form.querySelectorAll('.js-toggle-group').forEach(function (btn) {
            var group = boxes.filter(function (b) { return b.dataset.group === btn.dataset.group; });
            var allOn = group.every(function (b) { return b.checked; });
            var name = btn.textContent.replace(/^(Untick|Tick) all in /, '');
            btn.textContent = (allOn ? 'Untick' : 'Tick') + ' all in ' + name;
        });
    }
    form.querySelectorAll('.js-toggle-group').forEach(function (btn) {
        btn.hidden = false;
        btn.addEventListener('click', function () {
            var group = boxes.filter(function (b) { return b.dataset.group === btn.dataset.group; });
            var allOn = group.every(function (b) { return b.checked; });
            group.forEach(function (b) { b.checked = !allOn; });
            refresh();
        });
    });
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    refresh();
})();
</script>
@endpush
