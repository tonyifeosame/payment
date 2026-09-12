@extends('layouts.admin')

@section('title', 'Edit fee type')
@section('heading', 'Edit fee type')
@section('subheading', $subcategory->name)

@section('content')
<form action="{{ route('school.subcategories.update', ['school' => $school->slug, 'subcategory' => $subcategory->id]) }}" method="POST" class="card p-6 max-w-lg">
    @csrf
    @method('PUT')
    @include('subcategories._form')
    <div class="flex gap-2 mt-6">
        <button type="submit" class="btn-primary">Update fee type</button>
        <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
