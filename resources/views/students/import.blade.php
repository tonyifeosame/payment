@extends('layouts.admin')

@section('subnav')
    @include('admin._subnav', ['section' => 'students'])
@endsection

@section('title', 'Import students')
@section('eyebrow', 'School · Students')
@section('heading', 'Import students')
@section('subheading', 'Add many students at once from a CSV file. Existing students are never changed.')
@section('actions')
    <a href="{{ route('school.students.index', ['school' => $school->slug]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        All students
    </a>
@endsection

@section('content')
@php $s = ['school' => $school->slug]; @endphp

@if(request()->boolean('too_large') && ! $result)
    <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert" data-too-large>
        The file was larger than 10 MB, so it was not uploaded. Split it into smaller files and import each one.
    </div>
@endif

@if($result)
    {{-- Summary of the import just run --}}
    <section class="card mb-6 p-5 sm:p-6" aria-labelledby="result-heading" data-import-result>
        <h2 id="result-heading" class="font-display text-lg font-bold tracking-tight">Import summary</h2>
        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-2xl border border-green-200 bg-green-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-green-800">Imported</p>
                <p class="mt-1 font-display text-2xl font-extrabold tabular-nums text-green-900" data-imported-count>{{ $result['imported'] }}</p>
                <p class="text-sm text-green-900">{{ Str::plural('student', $result['imported']) }} added to your roster</p>
            </div>
            <div class="rounded-2xl border {{ $result['failed'] ? 'border-red-200 bg-red-50' : 'border-brand-ash/60' }} p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] {{ $result['failed'] ? 'text-red-700' : 'text-brand-slate' }}">Not imported</p>
                <p class="mt-1 font-display text-2xl font-extrabold tabular-nums" data-failed-count>{{ count($result['failed']) }}</p>
                <p class="text-sm text-brand-slate">{{ Str::plural('row', count($result['failed'])) }} with problems, listed below</p>
            </div>
        </div>
        @if($result['ignored_columns'])
            <p class="mt-4 text-sm text-brand-slate">Ignored {{ Str::plural('column', count($result['ignored_columns'])) }} not in the template: <span class="font-mono">{{ implode(', ', $result['ignored_columns']) }}</span>.</p>
        @endif
        <div class="mt-5 flex flex-col gap-3 sm:flex-row">
            <a href="{{ route('school.students.index', $s) }}" class="btn-obsidian">View students</a>
        </div>
    </section>

    @if($result['failed'])
        <section class="mb-6" aria-labelledby="failed-heading">
            <div class="mb-3 px-1">
                <h2 id="failed-heading" class="font-display text-lg font-bold tracking-tight">Rows not imported</h2>
                <p class="text-sm text-brand-slate">Correct these rows in your file and import it again — rows already imported will be reported as existing and left unchanged.</p>
            </div>
            <x-admin.table :columns="[['Row', 'left', 'w-px'], ['Admission no.'], ['Why it was not imported']]" caption="Rows that were not imported, with reasons" stacked>
                @foreach($result['failed'] as $failure)
                    <tr>
                        <td class="td tabular-nums" data-label="Row">{{ $failure['row'] }}</td>
                        <td class="td font-mono text-[13px]" data-label="Admission no.">{{ $failure['admission_number'] !== '' ? $failure['admission_number'] : '—' }}</td>
                        <td class="td" data-label="Reason">
                            <ul class="space-y-1">
                                @foreach($failure['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                            </ul>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        </section>
    @endif
@endif

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
    <form method="POST" action="{{ route('school.students.import.store', $s) }}" enctype="multipart/form-data" class="card p-5 sm:p-6 lg:col-span-2" aria-labelledby="upload-heading">
        @csrf
        <h2 id="upload-heading" class="font-display text-lg font-bold tracking-tight">Upload a CSV file</h2>
        <p class="mt-1 text-sm text-brand-slate">Up to {{ number_format(\App\Services\StudentImportService::MAX_ROWS) }} students and 10 MB per file. Every row is checked first; rows with problems are skipped and listed with the reason.</p>
        <div class="mt-4">
            <label for="file" class="field-label">CSV file</label>
            <input id="file" name="file" type="file" accept=".csv,text/csv" required class="field-input {{ $errors->has('file') ? 'field-input-error' : '' }}" @if($errors->has('file')) aria-invalid="true" @endif>
        </div>
        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route('school.students.import.template', $s) }}" class="btn-outline">Download template</a>
            <button type="submit" class="btn-obsidian">Import students</button>
        </div>
    </form>

    <section class="card p-5 sm:p-6" aria-labelledby="format-heading">
        <h2 id="format-heading" class="font-display text-lg font-bold tracking-tight">File format</h2>
        <ul class="mt-3 space-y-2 text-sm text-brand-slate">
            <li><span class="font-mono font-semibold text-brand-obsidian">full_name</span>, <span class="font-mono font-semibold text-brand-obsidian">admission_number</span> and <span class="font-mono font-semibold text-brand-obsidian">class</span> are required.</li>
            <li><span class="font-mono">class</span> must match one of <a href="{{ route('school.students.classes.index', $s) }}" class="font-medium text-brand-violet underline underline-offset-2">your classes</a> (capital letters don’t matter).</li>
            <li><span class="font-mono">status</span> is optional: active (the default), graduated or left.</li>
            <li><span class="font-mono">academic_year</span> is optional, and must be a year your school already has, e.g. 2026/2027.</li>
            <li><span class="font-mono">guardian_name</span>, <span class="font-mono">guardian_phone</span> and <span class="font-mono">guardian_email</span> are optional.</li>
            <li>Admission numbers must be unique. A student who already exists is reported and never changed.</li>
            <li>Save from Excel as “CSV UTF-8 (Comma delimited)”.</li>
        </ul>
    </section>
</div>
@endsection
