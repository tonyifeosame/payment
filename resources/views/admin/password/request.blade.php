@extends('layouts.marketing')

@section('title')
    Forgot password — @include('marketing.partials.brand-name')
@endsection
@section('meta_description', 'Request a link to reset the password for your school\'s FEYRA account.')

@section('nav')
    @include('marketing.partials.slim-header', [
        'prompt' => 'Remembered it?',
        'actionLabel' => 'Sign in',
        'actionHref' => route('admin.login'),
    ])
@endsection

@section('footer')
    @include('marketing.partials.slim-footer')
@endsection

@section('content')
<div class="container-x py-10 sm:py-14 lg:py-20">
    <div class="mx-auto w-full max-w-md">
        <div class="text-center">
            <h1 class="font-display text-3xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian sm:text-4xl">Forgot your password?</h1>
            <p class="mt-3 text-brand-slate">Enter your email address and we will send you a link to reset your password.</p>
        </div>

        <div class="mt-8 rounded-4xl bg-white p-6 shadow-[0_20px_60px_-30px_rgba(18,18,23,0.25)] sm:p-8">
            @if (session('status'))
                <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800" role="status">{{ session('status') }}</div>
            @endif

            <form action="{{ route('admin.password.email') }}" method="POST" class="space-y-5" data-submit-once>
                @csrf

                <div>
                    <label for="email" class="field-label">Email address</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                           class="field-input{{ $errors->has('email') ? ' field-input-error' : '' }}"
                           @error('email') aria-invalid="true" aria-describedby="email-error" @enderror />
                    @include('marketing.partials.field-error', ['field' => 'email'])
                </div>

                <div class="pt-2">
                    @include('marketing.partials.submit-button', ['label' => 'Send reset link', 'busy' => 'Sending…'])
                </div>
            </form>
        </div>

        <p class="mt-6 text-center text-sm">
            <a href="{{ route('admin.login') }}" class="font-semibold text-brand-violet hover:underline">&larr; Back to sign in</a>
        </p>
    </div>
</div>
@endsection
