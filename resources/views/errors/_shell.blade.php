{{-- Shared shell for the custom HTTP error pages (404, 419, 429, 500, 503), which
     Laravel's handler renders as errors::{status}. Named _shell, not layout, so it does
     not shadow the framework's own errors::layout used by the other status pages.

     Error pages can render with no session (unmatched URLs, maintenance mode, the
     webhook route) and while the database is down, so nothing here may touch either.
     The exception is never printed: pages show fixed copy only. --}}
@extends('layouts.marketing')

@section('title')
    @yield('heading') — @include('marketing.partials.brand-name')
@endsection

@section('nav')
    @include('marketing.partials.slim-header', [
        'actionLabel' => 'Contact us',
        'actionHref' => route('contact.show'),
    ])
@endsection

@section('footer')
    @include('marketing.partials.slim-footer')
@endsection

@section('content')
<div class="container-x flex min-h-[60vh] items-center justify-center py-12 sm:py-16">
    <div class="w-full max-w-xl rounded-4xl border border-brand-ash/60 bg-white p-6 text-center sm:p-10">
        <span class="eyebrow-violet">Error @yield('code')</span>
        <h1 class="mt-5 font-display text-3xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian sm:text-4xl">@yield('heading')</h1>
        <p class="mt-4 text-lg text-brand-slate">@yield('message')</p>
        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            @yield('actions')
        </div>
    </div>
</div>
@endsection
