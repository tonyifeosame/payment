@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8">
        <h1 class="text-3xl font-bold text-slate-900">Contact Us</h1>
        <p class="mt-2 text-slate-600">Have a question or need help? Send us a message and we’ll get back to you shortly.</p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <a href="https://wa.me/2348143369102" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-emerald-600 text-white hover:bg-emerald-700">
                WhatsApp: 08143369102
            </a>
            <a href="https://www.instagram.com/tifeosame?igsh=MTQ5cW5sNGlhbHp5cA==" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-pink-600 text-white hover:bg-pink-700">
                Instagram: @tifeosame
            </a>
        </div>

        @if(session('success'))
            <div class="mt-4 rounded-md border border-green-200 bg-green-50 p-3 text-green-700">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-red-700">
                {{ session('error') }}
            </div>
        @endif

        <form method="POST" action="{{ route('contact.send') }}" class="mt-6 space-y-5">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700" for="name">Your Name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                @error('name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="email">Your Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                @error('email')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="subject">Subject</label>
                <input id="subject" name="subject" type="text" value="{{ old('subject') }}" required
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                @error('subject')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="message">Message</label>
                <textarea id="message" name="message" rows="6" required
                          class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500">{{ old('message') }}</textarea>
                @error('message')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="pt-2 flex items-center gap-3">
                <a href="{{ route('home') }}" class="px-4 py-2 rounded-md border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
                <button type="submit" class="px-5 py-2.5 rounded-md bg-blue-600 text-white hover:bg-blue-700">Send Message</button>
            </div>
        </form>
    </div>
</div>
@endsection
