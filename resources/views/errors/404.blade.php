@extends('errors._shell')

@section('code', '404')
@section('heading', 'Page not found')
@section('message', 'We couldn’t find the page you were looking for. The link may be mistyped, or the page may have moved.')

@section('actions')
    <a href="{{ route('home') }}" class="btn-obsidian">Go to the homepage</a>
    <a href="{{ route('contact.show') }}" class="btn-outline">Contact support</a>
@endsection
