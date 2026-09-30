@extends('errors._shell')

@php
    // Retry by reloading the page the form came from, so the browser gets a fresh
    // token. The session's record of that page is gone when the session itself has
    // expired, so the Referer is the fallback. Either is used only when it is on this
    // site; url()->previous() would follow a Referer anywhere.
    $home = url('/');
    $onThisSite = fn ($url) => is_string($url) && ($url === $home || str_starts_with($url, $home.'/'));
    $retryUrl = collect([
        request()->hasSession() ? request()->session()->previousUrl() : null,
        request()->headers->get('referer'),
    ])->first($onThisSite) ?? route('home');
@endphp

@section('code', '419')
@section('heading', 'This page has expired')
@section('message', 'For your security, your session expired before the form was sent, so nothing was submitted. Reload the page and try again.')

@section('actions')
    <a href="{{ $retryUrl }}" class="btn-obsidian">Reload and try again</a>
    <a href="{{ route('home') }}" class="btn-outline">Go to the homepage</a>
@endsection
