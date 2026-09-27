@extends('layouts.marketing')

@section('title')
    Reset password — @include('marketing.partials.brand-name')
@endsection

@section('nav')
    @include('marketing.partials.slim-header', [
        'actionLabel' => 'Sign in',
        'actionHref' => route('admin.login'),
    ])
@endsection

@section('footer')
    @include('marketing.partials.slim-footer')
@endsection

@section('content')
@php
    $inputClass = fn (string $field) => 'field-input'.($errors->has($field) ? ' field-input-error' : '');
@endphp

<div class="container-x py-10 sm:py-14 lg:py-20">
    <div class="mx-auto w-full max-w-md">
        <div class="text-center">
            <h1 class="font-display text-3xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian sm:text-4xl">Reset your password</h1>
            <p class="mt-3 text-brand-slate">Choose a new password for your school's admin account.</p>
        </div>

        <div class="mt-8 rounded-4xl bg-white p-6 shadow-[0_20px_60px_-30px_rgba(18,18,23,0.25)] sm:p-8">
            @if ($errors->any())
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-5" role="alert" aria-labelledby="error-summary-heading">
                    <p id="error-summary-heading" class="font-semibold text-red-800">Please fix the following:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('admin.password.update') }}" method="POST" class="space-y-5" data-submit-once>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div>
                    <label for="email" class="field-label">Email address</label>
                    <input id="email" name="email" type="email" value="{{ $email ?? old('email') }}" required autofocus autocomplete="email"
                           class="{{ $inputClass('email') }}"
                           @error('email') aria-invalid="true" @enderror />
                </div>

                <div>
                    <label for="password" class="field-label">New password</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                           class="{{ $inputClass('password') }}"
                           @error('password') aria-invalid="true" @enderror />
                </div>

                <div>
                    <label for="password_confirmation" class="field-label">Confirm new password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                           class="{{ $inputClass('password') }}" />
                </div>

                <div class="pt-2">
                    @include('marketing.partials.submit-button', ['label' => 'Reset password', 'busy' => 'Saving…'])
                </div>
            </form>
        </div>

        <p class="mt-6 text-center text-sm">
            <a href="{{ route('admin.login') }}" class="font-semibold text-brand-violet hover:underline">&larr; Back to sign in</a>
        </p>
    </div>
</div>
@endsection
