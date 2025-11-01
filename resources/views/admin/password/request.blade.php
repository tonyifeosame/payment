@extends('layouts.app')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8">
        <h1 class="text-2xl font-bold text-slate-800">Forgot Password</h1>
        <p class="text-slate-600 mt-1">Enter your email address and we will send you a link to reset your password.</p>

        @if (session('status'))
            <div class="mt-4 mb-4 rounded-md border border-green-200 bg-green-50 p-3 text-green-700">
                {{ session('status') }}
            </div>
        @endif

        <form action="{{ route('admin.password.email') }}" method="POST" class="mt-6 space-y-5">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700" for="email">Email Address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
            </div>

            <div class="pt-2 flex items-center gap-3">
                <a href="{{ route('admin.login') }}" class="px-4 py-2 rounded-md border border-slate-300 text-slate-700 hover:bg-slate-50">Back to Login</a>
                <button type="submit" class="px-5 py-2.5 rounded-md bg-blue-600 text-white hover:bg-blue-700">Send Password Reset Link</button>
            </div>
        </form>
    </div>
</div>
@endsection
