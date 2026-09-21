@extends('layouts.admin')

@section('title', 'Edit category')
@section('eyebrow', 'Fee setup · Categories')
@section('heading', 'Edit category')
@section('subheading', $category->name)
@section('inline-errors', '1')
@section('actions')
    <a href="{{ route('school.categories.index', ['school' => $school->slug]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        All categories
    </a>
@endsection

@section('content')
<form action="{{ route('school.categories.update', ['school' => $school->slug, 'category' => $category->id]) }}" method="POST" class="card max-w-xl p-5 sm:p-6" aria-labelledby="form-heading">
    @csrf
    @method('PUT')
    <h2 id="form-heading" class="sr-only">Category details</h2>
    <div>
        <label for="name" class="field-label">Name</label>
        <input type="text" name="name" id="name" value="{{ old('name', $category->name) }}" class="field-input {{ $errors->has('name') ? 'field-input-error' : '' }}" required maxlength="255" autocomplete="off" aria-describedby="name-help{{ $errors->has('name') ? ' name-error' : '' }}" @if($errors->has('name')) aria-invalid="true" @endif>
        <p id="name-help" class="field-help">Renaming keeps every fee type in this category. Past payments keep the name they were made under.</p>
        @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
    </div>
    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
        <a href="{{ route('school.categories.index', ['school' => $school->slug]) }}" class="btn-outline">Cancel</a>
        <button type="submit" class="btn-obsidian">Save changes</button>
    </div>
</form>
@endsection
