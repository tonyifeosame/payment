@extends('errors._shell')

@section('code', '503')
@section('heading', 'We’ll be back shortly')
@section('message', 'FEYRA is temporarily unavailable, usually for scheduled maintenance. Please try again in a few minutes.')

@section('actions')
    {{-- Reload the page they were on; a non-GET request cannot be replayed by a link. --}}
    <a href="{{ request()->isMethod('GET') ? request()->fullUrl() : route('home') }}" class="btn-obsidian">Try again</a>
@endsection
