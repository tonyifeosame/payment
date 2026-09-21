@extends('layouts.admin')

@section('title', 'Edit student')
@section('eyebrow', 'School · Students')
@section('heading', 'Edit student')
@section('subheading', $student->full_name.' · '.$student->admission_number)
@section('inline-errors', '1')
@section('actions')
    <a href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $student->id]) }}" class="btn-outline">View student</a>
@endsection

@section('content')
<form method="POST" action="{{ route('school.students.update', ['school' => $school->slug, 'student' => $student->id]) }}" class="card max-w-3xl p-5 sm:p-6" aria-labelledby="form-heading">
    @csrf
    @method('PUT')
    <h2 id="form-heading" class="sr-only">Student details</h2>
    @include('students._form')
    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
        <a href="{{ route('school.students.index', ['school' => $school->slug]) }}" class="btn-outline">Cancel</a>
        <button type="submit" class="btn-obsidian">Save changes</button>
    </div>
</form>
@endsection
