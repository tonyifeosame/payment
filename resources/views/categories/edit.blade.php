@extends('layouts.admin')

@section('title', 'Edit category')
@section('heading', 'Edit category')

@section('content')
<form action="{{ route('school.categories.update', ['school' => $school->slug, 'category' => $category->id]) }}" method="POST" class="card p-6 max-w-lg">
    @csrf
    @method('PUT')
    <div>
        <label for="name" class="label">Category name</label>
        <input type="text" name="name" id="name" value="{{ old('name', $category->name) }}" class="input" required maxlength="255">
        @error('name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
    </div>
    <div class="flex gap-2 mt-6">
        <button type="submit" class="btn-primary">Update category</button>
        <a href="{{ route('school.categories.index', ['school' => $school->slug]) }}" class="btn-secondary">Cancel</a>
    </div>
</form>
@endsection
