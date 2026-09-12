@extends('layouts.admin')

@section('title', 'Add student')
@section('heading', 'Add student')

@section('content')
<form method="POST" action="{{ route('school.students.store', ['school' => $school->slug]) }}" class="card p-6 max-w-3xl">
    @csrf
    @include('students._form', ['student' => null])
    <div class="flex gap-2 mt-6">
        <button type="submit" class="btn-primary">Save student</button>
        <a href="{{ route('school.students.index', ['school' => $school->slug]) }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
