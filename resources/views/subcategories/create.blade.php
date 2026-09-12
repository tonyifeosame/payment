@extends('layouts.admin')

@section('title', 'Add fee type')
@section('heading', 'Add fee type')

@section('content')
<form action="{{ route('school.subcategories.store', ['school' => $school->slug]) }}" method="POST" class="card p-6 max-w-lg">
    @csrf
    @include('subcategories._form', ['subcategory' => null])
    <div class="flex gap-2 mt-6">
        <button type="submit" class="btn-primary">Save</button>
        <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
