@extends('layouts.admin')

@section('title', 'Classes')
@section('eyebrow', 'School · Students')
@section('heading', 'Class progression')
@section('subheading', 'The classes your school runs, in the order students normally move through them. The last active class graduates.')
@section('inline-errors', '1')
@section('actions')
    <a href="{{ route('school.students.index', ['school' => $school->slug]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        All students
    </a>
@endsection

@section('content')
@php $s = ['school' => $school->slug]; @endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-5 lg:items-start">

    {{-- Add a class --}}
    <form method="POST" action="{{ route('school.students.classes.store', $s) }}" class="card p-5 sm:p-6 lg:col-span-2 lg:sticky lg:top-10" aria-labelledby="new-class-heading">
        @csrf
        <h2 id="new-class-heading" class="font-display text-lg font-bold tracking-tight">Add a class</h2>
        <p class="mt-1 text-sm text-brand-slate">New classes go to the bottom of the ladder; move them into place with the arrows.</p>
        <div class="mt-5">
            <label for="name" class="field-label">Class name</label>
            @php $addError = $errors->has('name') && ! old('_level'); @endphp
            <input id="name" name="name" value="{{ old('_level') ? '' : old('name') }}" class="field-input {{ $addError ? 'field-input-error' : '' }}" required maxlength="100" placeholder="e.g. Primary 1, JSS 1, Basic 4" autocomplete="off" @if($addError) aria-invalid="true" aria-describedby="name-error" @endif>
            @if($addError)<p id="name-error" class="field-error">{{ $errors->first('name') }}</p>@endif
        </div>
        <button type="submit" class="btn-obsidian mt-5 w-full">Add class</button>
        <p class="mt-4 text-sm text-brand-slate">Use the exact names your school uses. Nothing is assumed from a name: “JSS 2” follows “JSS 1” only because you place it there.</p>
    </form>

    <div class="space-y-6 lg:col-span-3">
        {{-- The ladder --}}
        <section class="card overflow-hidden" aria-labelledby="ladder-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-5 py-4 sm:px-6">
                <h2 id="ladder-heading" class="font-display text-lg font-bold tracking-tight">Your ladder</h2>
                <p class="text-sm text-brand-slate">{{ $levels->count() }} {{ Str::plural('class', $levels->count()) }} · top to bottom is first to last</p>
            </div>
            @if($levels->isEmpty())
                <div class="border-t border-brand-ash/60">
                    <x-admin.empty title="No classes yet" description="Add your first class on the left — for example the youngest class you admit — then the next, and so on." icon="M4 6h16M4 12h16M4 18h10" compact />
                </div>
            @else
                <ol class="divide-y divide-brand-fog border-t border-brand-ash/60" aria-label="Class progression, first to last">
                    @foreach($levels as $level)
                        <li class="px-5 py-3 sm:px-6 {{ $level->is_active ? '' : 'bg-brand-fog/60' }}">
                            <div class="flex items-center gap-3 sm:gap-4">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-fog font-display text-sm font-bold text-brand-slate" aria-hidden="true">{{ $loop->iteration }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold {{ $level->is_active ? '' : 'text-brand-slate' }}">
                                        <span class="sr-only">Position {{ $loop->iteration }}: </span>{{ $level->name }}
                                        @unless($level->is_active) @include('admin._badge', ['status' => 'inactive', 'label' => 'Inactive']) @endunless
                                    </p>
                                    <p class="text-xs text-brand-slate">
                                        {{ $level->students_count }} {{ Str::plural('student', $level->students_count) }}
                                        @php $next = $level->nextIn($levels); @endphp
                                        @if($level->is_active)<span aria-hidden="true">·</span> next: {{ $next ? $next->name : 'Graduated' }}@endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <form method="POST" action="{{ route('school.students.classes.move', $s + ['classLevel' => $level->id]) }}">
                                        @csrf
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="inline-flex h-12 w-12 items-center justify-center rounded-xl text-brand-obsidian hover:bg-brand-fog focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 disabled:opacity-30" aria-label="Move {{ $level->name }} up" @disabled($loop->first)>
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5m-7 7l7-7 7 7"/></svg>
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('school.students.classes.move', $s + ['classLevel' => $level->id]) }}">
                                        @csrf
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="inline-flex h-12 w-12 items-center justify-center rounded-xl text-brand-obsidian hover:bg-brand-fog focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 disabled:opacity-30" aria-label="Move {{ $level->name }} down" @disabled($loop->last)>
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14m7-7l-7 7-7-7"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <details class="mt-2 pl-11 sm:pl-12" @if(old('_level') == $level->id) open @endif>
                                <summary class="inline-flex min-h-[48px] cursor-pointer list-none items-center gap-1.5 rounded-xl px-2 text-sm font-semibold text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 [&::-webkit-details-marker]:hidden">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
                                    Edit {{ $level->name }}
                                </summary>
                                <form method="POST" action="{{ route('school.students.classes.update', $s + ['classLevel' => $level->id]) }}" class="mt-2 rounded-2xl border border-brand-ash/60 bg-brand-fog/40 p-4">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="_level" value="{{ $level->id }}">
                                    <label for="name-{{ $level->id }}" class="field-label">Name</label>
                                    @php $editError = $errors->has('name') && old('_level') == $level->id; @endphp
                                    <input id="name-{{ $level->id }}" name="name" value="{{ old('_level') == $level->id ? old('name') : $level->name }}" class="field-input {{ $editError ? 'field-input-error' : '' }}" required maxlength="100" autocomplete="off" @if($editError) aria-invalid="true" aria-describedby="name-{{ $level->id }}-error" @endif>
                                    @if($editError)<p id="name-{{ $level->id }}-error" class="field-error">{{ $errors->first('name') }}</p>@endif
                                    <label class="mt-4 flex min-h-[48px] cursor-pointer items-center gap-3 text-sm">
                                        <input type="checkbox" name="is_active" value="1" class="h-5 w-5 rounded border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" @checked($level->is_active)>
                                        <span><span class="font-semibold">Active</span> <span class="text-brand-slate">— inactive classes are skipped in promotion and cannot be chosen for new students.</span></span>
                                    </label>
                                    <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
                                        <button type="submit" class="btn-obsidian btn-sm !min-h-[48px]">Save</button>
                                    </div>
                                </form>
                                @if($level->students_count === 0)
                                    <form method="POST" action="{{ route('school.students.classes.destroy', $s + ['classLevel' => $level->id]) }}" class="mt-2"
                                          data-confirm="“{{ $level->name }}” has no students and will be removed from your ladder. Promotion history that mentions it keeps its name."
                                          data-confirm-title="Remove {{ $level->name }}?" data-confirm-label="Remove class" data-confirm-tone="danger">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-danger btn-sm !min-h-[48px]">Remove class</button>
                                    </form>
                                @endif
                            </details>
                        </li>
                    @endforeach
                    <li class="flex items-center gap-3 px-5 py-3 text-sm text-brand-slate sm:gap-4 sm:px-6" aria-label="After the last active class: graduated">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-fog" aria-hidden="true">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10L12 5 2 10l10 5 10-5zM6 12v5c3 3 9 3 12 0v-5"/></svg>
                        </span>
                        <span>Then: <span class="font-semibold text-brand-obsidian">Graduated</span> — students leaving the last active class keep their record with the status “Graduated”.</span>
                    </li>
                </ol>
            @endif
        </section>

        {{-- Legacy free-text classes --}}
        @if($unassigned->isNotEmpty())
            <section class="card p-5 sm:p-6" aria-labelledby="legacy-heading">
                <h2 id="legacy-heading" class="font-display text-lg font-bold tracking-tight">Students with an unassigned class</h2>
                <p class="mt-1 text-sm text-brand-slate">These students were recorded with a typed class name before your ladder existed. Choose which class each name belongs to — nothing is matched automatically.</p>
                <ul class="mt-4 divide-y divide-brand-fog">
                    @foreach($unassigned as $row)
                        <li class="py-4">
                            <form method="POST" action="{{ route('school.students.classes.assign', $s) }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                @csrf
                                <input type="hidden" name="class_name" value="{{ $row->class_name }}">
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold">“{{ $row->class_name ?: '(blank)' }}”</p>
                                    <p class="text-xs text-brand-slate">{{ $row->students_count }} {{ Str::plural('student', $row->students_count) }}</p>
                                </div>
                                <div class="sm:w-64">
                                    <label for="assign-{{ $loop->index }}" class="field-label">Assign to</label>
                                    <select id="assign-{{ $loop->index }}" name="class_level_id" class="field-input" required>
                                        <option value="">Choose a class</option>
                                        @foreach($levels as $level)
                                            <option value="{{ $level->id }}">{{ $level->name }}</option>
                                        @endforeach
                                        @if($row->class_name !== '' && $row->class_name !== null)
                                            <option value="new">Create a class named “{{ $row->class_name }}”</option>
                                        @endif
                                    </select>
                                </div>
                                <button type="submit" class="btn-outline btn-sm !min-h-[48px]">Assign</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
@endsection
