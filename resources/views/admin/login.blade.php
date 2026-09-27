@extends('layouts.marketing')

@section('title')
    Sign in — @include('marketing.partials.brand-name')
@endsection
@section('meta_description', 'Sign in to your school\'s FEYRA account to manage fees, students, payments and payouts.')

@section('nav')
    @include('marketing.partials.slim-header', [
        'prompt' => 'New to FEYRA?',
        'actionLabel' => 'Get started',
        'actionHref' => route('registration.create'),
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
            <h1 class="font-display text-3xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian sm:text-4xl">Sign in</h1>
            <p class="mt-3 text-brand-slate">Manage your school's payment system.</p>
        </div>

        <div class="mt-8 rounded-4xl bg-white p-6 shadow-[0_20px_60px_-30px_rgba(18,18,23,0.25)] sm:p-8">
            @if(session('error'))
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800" role="alert">{{ session('error') }}</div>
            @endif
            @if(session('success'))
                <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800" role="status">{{ session('success') }}</div>
            @endif
            @if(session('status'))
                <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800" role="status">{{ session('status') }}</div>
            @endif

            <form action="{{ route('admin.login.post') }}" method="POST" class="space-y-5" data-submit-once>
                @csrf

                <div>
                    <label for="name" class="field-label">School name</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="organization"
                           class="{{ $inputClass('name') }}"
                           placeholder="Enter your school name"
                           @error('name') aria-invalid="true" @enderror
                           aria-describedby="name-help{{ $errors->has('name') ? ' name-error' : '' }}" />
                    <p id="name-help" class="field-help">The school's registered name (as entered at registration), not its email address.</p>
                    @include('marketing.partials.field-error', ['field' => 'name'])
                </div>

                <div>
                    <label for="password" class="field-label">Admin password</label>
                    <div class="relative">
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                               class="{{ $inputClass('password') }} pr-14"
                               placeholder="Enter your password"
                               @error('password') aria-invalid="true" aria-describedby="password-error" @enderror />
                        <button type="button" id="togglePassword"
                                class="absolute inset-y-0 right-0 mt-2 flex w-12 items-center justify-center rounded-r-xl text-brand-slate hover:text-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30"
                                aria-label="Show password" aria-pressed="false">
                            <svg id="eyeIcon" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                        </button>
                    </div>
                    @include('marketing.partials.field-error', ['field' => 'password'])
                </div>

                <div class="flex items-center justify-between gap-4">
                    <label for="remember" class="inline-flex cursor-pointer select-none items-center gap-2 text-sm text-brand-obsidian">
                        <input id="remember" name="remember" type="checkbox"
                               class="h-4 w-4 rounded border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/20" />
                        Remember me
                    </label>
                    <a href="{{ route('admin.password.request') }}" class="text-sm font-semibold text-brand-violet hover:underline">Forgot password?</a>
                </div>

                <div class="pt-2">
                    @include('marketing.partials.submit-button', ['label' => 'Sign in', 'busy' => 'Signing in…'])
                </div>
            </form>
        </div>

        <p class="mt-6 text-center text-sm text-brand-slate">
            Don't have an account?
            <a href="{{ route('registration.create') }}" class="font-semibold text-brand-violet hover:underline">Register your school</a>
        </p>
        <p class="mt-3 text-center text-sm">
            <a href="{{ route('home') }}" class="text-brand-slate hover:text-brand-obsidian">&larr; Back to homepage</a>
        </p>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var toggle = document.getElementById('togglePassword');
    var input = document.getElementById('password');
    var icon = document.getElementById('eyeIcon');
    if (!toggle || !input || !icon) return;

    var shown = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>';
    var hidden = icon.innerHTML;

    toggle.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        icon.innerHTML = show ? shown : hidden;
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();
</script>
@endpush
