@extends('layouts.admin')

@section('title', 'Edit student')
@section('heading', 'Edit student')
@section('subheading', $student->full_name.' · '.$student->admission_number)

@section('content')
<form method="POST" action="{{ route('school.students.update', ['school' => $school->slug, 'student' => $student->id]) }}" class="card p-6 max-w-3xl">
    @csrf
    @method('PUT')
    @include('students._form')
    <div class="flex gap-2 mt-6">
        <button type="submit" class="btn-primary">Save changes</button>
        <a href="{{ route('school.students.index', ['school' => $school->slug]) }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
