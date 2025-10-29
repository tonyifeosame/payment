@extends('layouts.app')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8">
        <h1 class="text-2xl font-bold text-slate-800">Reset Password</h1>

        @if ($errors->any())
            <div class="mt-4 mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-red-700">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('admin.password.update') }}" method="POST" class="mt-6 space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label class="block text-sm font-medium text-slate-700" for="email">Email Address</label>
                <input id="email" name="email" type="email" value="{{ $email ?? old('email') }}" required autofocus
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700" for="password">Password</label>
                <input id="password" name="password" type="password" required
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700" for="password_confirmation">Confirm Password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
            </div>

            <div class="pt-2">
                <button type="submit" class="w-full px-5 py-2.5 rounded-md bg-blue-600 text-white hover:bg-blue-700">Reset Password</button>
            </div>
        </form>
    </div>
</div>
@endsection
