@extends('layouts.admin')

@section('title', 'Add student')
@section('eyebrow', 'School · Students')
@section('heading', 'Add student')
@section('subheading', 'Parents will pick this student on the payment page by name or admission number.')
@section('inline-errors', '1')

@section('content')
<form method="POST" action="{{ route('school.students.store', ['school' => $school->slug]) }}" class="card max-w-3xl p-5 sm:p-6" aria-labelledby="form-heading">
    @csrf
    <h2 id="form-heading" class="sr-only">Student details</h2>
    @include('students._form', ['student' => null])
    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
        <a href="{{ route('school.students.index', ['school' => $school->slug]) }}" class="btn-outline">Cancel</a>
        <button type="submit" class="btn-obsidian">Save student</button>
    </div>
</form>
@endsection
