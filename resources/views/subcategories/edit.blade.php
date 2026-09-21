@extends('layouts.admin')

@section('title', 'Edit fee type')
@section('eyebrow', 'Fee setup · Fee types')
@section('heading', 'Edit fee type')
@section('subheading', $subcategory->name.($subcategory->category ? ' · '.$subcategory->category->name : ''))
@section('inline-errors', '1')
@section('actions')
    <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        All fee types
    </a>
@endsection

@section('content')
<form action="{{ route('school.subcategories.update', ['school' => $school->slug, 'subcategory' => $subcategory->id]) }}" method="POST" class="card max-w-xl p-5 sm:p-6" aria-labelledby="form-heading">
    @csrf
    @method('PUT')
    <h2 id="form-heading" class="sr-only">Fee type details</h2>
    <p class="mb-5 rounded-2xl bg-brand-fog px-4 py-3 text-sm text-brand-slate">You are editing an existing fee. Changing the amount affects new payments only; payments already made keep the amount they were made with.</p>
    @include('subcategories._form')
    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
        <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" class="btn-outline">Cancel</a>
        <button type="submit" class="btn-obsidian">Save changes</button>
    </div>
</form>
@endsection
