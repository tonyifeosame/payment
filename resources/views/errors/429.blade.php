@extends('errors._shell')

@section('code', '429')
@section('heading', 'Too many requests')
@section('message', 'We received too many requests from you in a short time. Please wait a few minutes before trying again.')

@section('actions')
    <a href="{{ route('home') }}" class="btn-obsidian">Go to the homepage</a>
    <a href="{{ route('contact.show') }}" class="btn-outline">Contact support</a>
@endsection
