@extends('layouts.admin')

@section('subnav')
    @include('admin._subnav', ['section' => 'fees'])
@endsection

@section('title', 'Add fee')
@section('eyebrow', 'Fees')
@section('heading', 'Add a fee')
@section('subheading', 'Choose the type of fee, the academic year and term, the amount and the classes it is for.')
@section('inline-errors', '1')
@section('actions')
    <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        All fees
    </a>
@endsection

@section('content')
<form action="{{ route('school.subcategories.store', ['school' => $school->slug]) }}" method="POST" class="card max-w-xl p-5 sm:p-6" aria-labelledby="form-heading">
    @csrf
    <h2 id="form-heading" class="sr-only">Fee details</h2>
    @include('subcategories._form', ['subcategory' => null])
    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
        <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" class="btn-outline">Cancel</a>
        <button type="submit" class="btn-obsidian">Create fee</button>
    </div>
</form>
@endsection
