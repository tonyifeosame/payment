@extends('layouts.admin')

@section('title', 'Students')
@section('heading', 'Students')
@section('subheading', 'Your roster. Parents identify a student on the payment page by admission number.')
@section('actions')
    <a href="{{ route('school.students.create', ['school' => $school->slug]) }}" class="btn-primary">+ Add student</a>
@endsection

@section('content')
<form method="GET" class="card p-4 mb-4 flex flex-col md:flex-row gap-3 md:items-end">
    <div class="flex-1">
        <label for="q" class="label">Search</label>
        <input id="q" name="q" value="{{ $q }}" class="input" placeholder="Name, admission number or class">
    </div>
    <div>
        <label for="class" class="label">Class</label>
        <select id="class" name="class" class="input">
            <option value="">All classes</option>
            @foreach($classes as $c)
                <option value="{{ $c }}" @selected($class === $c)>{{ $c }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex gap-2">
        <button class="btn-primary" type="submit">Filter</button>
        <a class="btn-secondary" href="{{ route('school.students.index', ['school' => $school->slug]) }}">Reset</a>
    </div>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="th">Admission No.</th>
                    <th class="th">Name</th>
                    <th class="th">Class</th>
                    <th class="th">Session</th>
                    <th class="th">Guardian</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse($students as $student)
                    <tr class="hover:bg-blue-50/50">
                        <td class="td font-mono font-bold">{{ $student->admission_number }}</td>
                        <td class="td font-semibold">
                            <a class="hover:underline" href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $student->id]) }}">{{ $student->full_name }}</a>
                        </td>
                        <td class="td">{{ $student->class_name }}</td>
                        <td class="td">{{ $student->session?->name ?? '—' }}</td>
                        <td class="td text-slate-600">
                            {{ $student->guardian_name ?? '—' }}
                            @if($student->guardian_phone)<span class="block text-xs">{{ $student->guardian_phone }}</span>@endif
                        </td>
                        <td class="td text-right whitespace-nowrap">
                            <a href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $student->id]) }}" class="btn-secondary !py-1.5 !px-3">Payments</a>
                            <a href="{{ route('school.students.edit', ['school' => $school->slug, 'student' => $student->id]) }}" class="btn-secondary !py-1.5 !px-3">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-slate-500">
                            @if($q !== '' || $class !== '')
                                No students match your search.
                            @else
                                No students yet. <a class="underline text-blue-700" href="{{ route('school.students.create', ['school' => $school->slug]) }}">Add the first one</a>.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($students->hasPages())
        <div class="px-4 py-3 border-t border-slate-100">{{ $students->links() }}</div>
    @endif
</div>
@endsection
