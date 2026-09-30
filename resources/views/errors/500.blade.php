@extends('errors._shell')

@section('code', '500')
@section('heading', 'Something went wrong')
@section('message', 'We hit a temporary problem on our side. Please try again in a few minutes. If it keeps happening, contact support.')

@section('actions')
    <a href="{{ route('home') }}" class="btn-obsidian">Go to the homepage</a>
    <a href="{{ route('contact.show') }}" class="btn-outline">Contact support</a>
@endsection
