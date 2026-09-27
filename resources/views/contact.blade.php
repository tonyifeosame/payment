@extends('layouts.marketing')

@section('title')
    Contact us — @include('marketing.partials.brand-name')
@endsection
@section('meta_description', 'Questions about collecting school fees with FEYRA? Send us a message or reach us on WhatsApp.')

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

<div class="container-x py-10 sm:py-12 lg:py-16">
    <div class="grid gap-8 lg:grid-cols-12 lg:gap-12">

        <aside class="lg:col-span-5" aria-labelledby="contact-heading">
            <span class="eyebrow-violet">Contact</span>
            <h1 id="contact-heading" class="mt-5 font-display text-3xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian sm:text-4xl lg:text-5xl">Contact us</h1>
            <p class="mt-4 text-lg text-brand-slate">Have a question or need help? Send us a message and we’ll get back to you shortly.</p>

            <ul class="mt-8 space-y-3">
                <li>
                    <a href="mailto:ifeosamenkem@gmail.com"
                       class="flex min-h-[56px] items-center gap-3 rounded-2xl border border-brand-ash/60 bg-white px-4 py-3 text-brand-obsidian hover:border-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-violet/10 text-brand-violet">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-slate">Official support email</span>
                            <span class="block break-all font-semibold">ifeosamenkem@gmail.com</span>
                        </span>
                    </a>
                </li>
                <li>
                    <a href="https://wa.me/2348143369102" target="_blank" rel="noopener"
                       class="flex min-h-[56px] items-center gap-3 rounded-2xl border border-brand-ash/60 bg-white px-4 py-3 text-brand-obsidian hover:border-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-violet/10 text-brand-violet">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.4A8.4 8.4 0 1 1 21 11.5z"/></svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-slate">WhatsApp</span>
                            <span class="block font-semibold">08143369102</span>
                        </span>
                    </a>
                </li>
            </ul>
        </aside>

        <div class="lg:col-span-7">
            <div class="rounded-4xl bg-white p-6 shadow-[0_20px_60px_-30px_rgba(18,18,23,0.25)] sm:p-8 lg:p-10">
                @if(session('success'))
                    <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800" role="status">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800" role="alert">{{ session('error') }}</div>
                @endif

                <form method="POST" action="{{ route('contact.send') }}" class="space-y-5" data-submit-once>
                    @csrf

                    <div>
                        <label for="name" class="field-label">Your name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required autocomplete="name"
                               class="{{ $inputClass('name') }}"
                               @error('name') aria-invalid="true" aria-describedby="name-error" @enderror />
                        @include('marketing.partials.field-error', ['field' => 'name'])
                    </div>

                    <div>
                        <label for="email" class="field-label">Your email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email"
                               class="{{ $inputClass('email') }}"
                               @error('email') aria-invalid="true" aria-describedby="email-error" @enderror />
                        @include('marketing.partials.field-error', ['field' => 'email'])
                    </div>

                    <div>
                        <label for="subject" class="field-label">Subject</label>
                        <input id="subject" name="subject" type="text" value="{{ old('subject') }}" required
                               class="{{ $inputClass('subject') }}"
                               @error('subject') aria-invalid="true" aria-describedby="subject-error" @enderror />
                        @include('marketing.partials.field-error', ['field' => 'subject'])
                    </div>

                    <div>
                        <label for="message" class="field-label">Message</label>
                        <textarea id="message" name="message" rows="6" required
                                  class="{{ $inputClass('message') }} py-3"
                                  @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ old('message') }}</textarea>
                        @include('marketing.partials.field-error', ['field' => 'message'])
                    </div>

                    <div class="pt-2">
                        @include('marketing.partials.submit-button', ['label' => 'Send message', 'busy' => 'Sending…'])
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>
@endsection
