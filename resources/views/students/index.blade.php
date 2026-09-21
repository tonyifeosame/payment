@extends('layouts.admin')

@section('title', 'Students')
@section('eyebrow', 'School')
@section('heading', 'Students')
@section('subheading', 'Your roster. Parents identify a student on the payment page by admission number.')
@section('actions')
    <a href="{{ route('school.students.classes.index', ['school' => $school->slug]) }}" class="btn-outline">Classes</a>
    <a href="{{ route('school.students.promotion.index', ['school' => $school->slug]) }}" class="btn-outline">Promote students</a>
    <a href="{{ route('school.students.create', ['school' => $school->slug]) }}" class="btn-obsidian">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Add student
    </a>
@endsection

@section('content')
@php
    $indexUrl = fn (array $except = []) => route('school.students.index', array_merge(['school' => $school->slug], Arr::except(request()->query(), array_merge($except, ['page']))));
    $activeFilters = [];
    if ($q !== '') {
        $activeFilters['q'] = ['Search', '“'.$q.'”'];
    }
    if ($class !== '') {
        $classLabel = ctype_digit($class) ? $levels->firstWhere('id', (int) $class)?->name : ($legacyClasses->contains($class) ? $class : null);
        if ($classLabel !== null) {
            $activeFilters['class'] = ['Class', $classLabel];
        }
    }
    if ($status !== \App\Models\Student::STATUS_ACTIVE) {
        $activeFilters['status'] = ['Status', $status === 'all' ? 'All students' : \App\Models\Student::STATUS_LABELS[$status]];
    }
@endphp

<form method="GET" action="{{ route('school.students.index', ['school' => $school->slug]) }}" class="card p-5 sm:p-6" role="search" aria-labelledby="filters-heading">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id="filters-heading" class="font-display text-lg font-bold tracking-tight">Find students</h2>
        <p class="text-sm text-brand-slate">Showing active students unless you choose another status.</p>
    </div>
    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-12">
        <div class="sm:col-span-2 lg:col-span-6">
            <label for="q" class="label">Search</label>
            <input id="q" name="q" type="search" value="{{ $q }}" class="input" placeholder="Name, admission number or class" autocomplete="off" enterkeyhint="search">
        </div>
        <div class="lg:col-span-3">
            <label for="class" class="label">Class</label>
            <select id="class" name="class" class="input">
                <option value="">All classes</option>
                @foreach($levels as $level)
                    <option value="{{ $level->id }}" @selected($class === (string) $level->id)>{{ $level->name }}@if(! $level->is_active) (inactive)@endif</option>
                @endforeach
                @if($legacyClasses->isNotEmpty())
                    <optgroup label="Not yet assigned to a class">
                        @foreach($legacyClasses as $name)
                            <option value="{{ $name }}" @selected($class === $name)>{{ $name }}</option>
                        @endforeach
                    </optgroup>
                @endif
            </select>
        </div>
        <div class="lg:col-span-3">
            <label for="status" class="label">Status</label>
            <select id="status" name="status" class="input">
                @foreach(\App\Models\Student::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
                <option value="all" @selected($status === 'all')>All students</option>
            </select>
        </div>
        <div class="flex flex-col gap-3 sm:col-span-2 sm:flex-row sm:justify-end lg:col-span-12">
            <a class="btn-outline sm:w-auto" href="{{ route('school.students.index', ['school' => $school->slug]) }}">Reset</a>
            <button class="btn-obsidian sm:w-auto" type="submit">Apply filters</button>
        </div>
    </div>
    @if($activeFilters)
        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-brand-ash/60 pt-5" role="group" aria-label="Active filters">
            <span class="mr-1 text-xs font-semibold uppercase tracking-[0.08em] text-brand-violet">{{ count($activeFilters) }} {{ Str::plural('filter', count($activeFilters)) }} active</span>
            @foreach($activeFilters as $chipKey => [$chipLabel, $chipValue])
                <a href="{{ $indexUrl([$chipKey]) }}" class="inline-flex min-h-[48px] items-center gap-1.5 rounded-full border border-brand-violet/30 bg-brand-violet/10 py-1 pl-4 pr-3 text-sm font-medium text-brand-violet hover:border-brand-violet hover:bg-brand-violet/15 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">
                    <span><span class="sr-only">Remove filter </span>{{ $chipLabel }}: {{ $chipValue }}</span>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </a>
            @endforeach
        </div>
    @endif
</form>

<div class="mb-3 mt-6 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
    <p class="text-sm text-brand-slate">
        <span class="font-display text-lg font-bold text-brand-obsidian">{{ number_format($students->total()) }}</span>
        @if($activeFilters) {{ Str::plural('student', $students->total()) }} match your filters @else active {{ Str::plural('student', $students->total()) }} @endif
        @if($counts['total'] !== $counts['active']) <span aria-hidden="true">·</span> {{ number_format($counts['total']) }} on the roster in total @endif
    </p>
    @if($students->hasPages())
        <p class="text-sm text-brand-slate">Page {{ $students->currentPage() }} of {{ $students->lastPage() }}</p>
    @endif
</div>

@if($levels->isEmpty() && $counts['total'] > 0)
    <div class="mb-4 flex gap-3 rounded-2xl border border-brand-violet/20 bg-brand-violet/10 p-4 text-sm" role="status">
        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-violet text-white" aria-hidden="true">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 8h.01M12 11v5"/></svg>
        </span>
        <p class="pt-0.5"><span class="font-semibold">Set up your class ladder</span> to filter by class and promote students at the end of the session. <a href="{{ route('school.students.classes.index', ['school' => $school->slug]) }}" class="font-semibold text-brand-violet underline underline-offset-2">Manage classes</a></p>
    </div>
@endif

<x-admin.table
    :columns="$students->isEmpty() ? [] : [['Admission no.', 'left', 'xl:w-[16%]'], ['Name', 'left', 'xl:w-[26%]'], ['Class', 'left', 'xl:w-[16%]'], ['Guardian', 'left', 'xl:w-[22%]'], ['Actions', 'actions', 'xl:w-[20%]']]"
    caption="Students of {{ $school->name }}"
    class="xl:[&_table]:w-full xl:[&_table]:table-fixed md:[&_.th]:px-3 md:[&_.td]:px-3"
    stacked>
    @forelse($students as $student)
        <tr>
            <td class="td" data-label="Admission no.">
                <div class="min-w-0">
                    <span class="break-all font-mono text-[13px] font-semibold">{{ $student->admission_number }}</span>
                    @if($student->session)<span class="block text-xs text-brand-slate">{{ $student->session->name }}</span>@endif
                </div>
            </td>
            <td class="td" data-label="Name">
                <div class="min-w-0">
                    <a class="rounded font-semibold text-brand-obsidian hover:text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30" href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $student->id]) }}">{{ $student->full_name }}</a>
                    @if($student->status !== \App\Models\Student::STATUS_ACTIVE)
                        <span class="mt-1 block md:inline md:ml-2">@include('admin._badge', ['status' => $student->status, 'label' => \App\Models\Student::STATUS_LABELS[$student->status]])</span>
                    @endif
                </div>
            </td>
            <td class="td" data-label="Class">
                <div class="min-w-0">
                    <span class="font-medium">{{ $student->class_name ?: '—' }}</span>
                    @if(! $student->class_level_id)
                        <span class="block text-xs text-brand-slate">Not yet assigned to a class</span>
                    @endif
                </div>
            </td>
            <td class="td" data-label="Guardian">
                <div class="min-w-0">
                    <span class="block">{{ $student->guardian_name ?? '—' }}</span>
                    @if($student->guardian_phone)<span class="block text-xs text-brand-slate">{{ $student->guardian_phone }}</span>@endif
                </div>
            </td>
            <td class="td td-actions" data-label="">
                <div class="flex w-full gap-2 md:w-auto md:justify-end">
                    <a class="btn-outline btn-sm !min-h-[48px] flex-1 !px-4 text-sm md:flex-none" href="{{ route('school.students.edit', ['school' => $school->slug, 'student' => $student->id]) }}">Edit<span class="sr-only"> {{ $student->full_name }}</span></a>
                    <a class="btn-outline btn-sm !min-h-[48px] flex-1 !px-4 text-sm md:flex-none" href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $student->id]) }}">View<span class="sr-only"> {{ $student->full_name }}</span></a>
                </div>
            </td>
        </tr>
    @empty
        <x-slot:empty>
            @if($activeFilters)
                <x-admin.empty title="No students match these filters" description="Try another name, admission number, class or status." icon="M21 21l-4.3-4.3M11 18a7 7 0 100-14 7 7 0 000 14z">
                    <a class="btn-outline" href="{{ route('school.students.index', ['school' => $school->slug]) }}">Reset filters</a>
                </x-admin.empty>
            @else
                <x-admin.empty title="No students yet" description="Add your first student. Parents will pick them on the payment page by name or admission number." icon="M16 7a4 4 0 11-8 0 4 4 0 018 0zM5 20a7 7 0 0114 0">
                    <a class="btn-obsidian" href="{{ route('school.students.create', ['school' => $school->slug]) }}">Add student</a>
                </x-admin.empty>
            @endif
        </x-slot:empty>
    @endforelse
    @if($students->hasPages())
        <x-slot:footer>{{ $students->links() }}</x-slot:footer>
    @endif
</x-admin.table>
@endsection
