@extends('layouts.app')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8">
        <h1 class="text-2xl font-bold text-slate-800">School Admin Login</h1>
        <p class="text-slate-600 mt-1">Use your school name and admin password to manage categories, subcategories and transactions.</p>

        @if(session('error'))
            <div class="mt-4 mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-red-700">
                {{ session('error') }}
            </div>
        @endif
        @if(session('success'))
            <div class="mt-4 mb-4 rounded-md border border-green-200 bg-green-50 p-3 text-green-700">
                {{ session('success') }}
            </div>
        @endif

        <form action="{{ route('admin.login.post') }}" method="POST" class="mt-6 space-y-5">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700" for="name">School Name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700" for="password">Admin Password</label>
                <input id="password" name="password" type="password" required
                       class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
            </div>
            <div class="flex items-center gap-2">
                <input id="toggle_login_pw" type="checkbox" class="rounded border-slate-300" />
                <label for="toggle_login_pw" class="text-sm text-slate-700 select-none">Show password</label>
            </div>

            <div class="pt-2 flex items-center gap-3">
                <a href="{{ route('home') }}" class="px-4 py-2 rounded-md border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
                <button type="submit" class="px-5 py-2.5 rounded-md bg-blue-600 text-white hover:bg-blue-700">Login</button>
            </div>
        </form>
        <script>
            (function(){
                const cb = document.getElementById('toggle_login_pw');
                const pw = document.getElementById('password');
                if (cb && pw) {
                    cb.addEventListener('change', function(){
                        pw.type = this.checked ? 'text' : 'password';
                    });
                }
            })();
        </script>
    </div>
</div>
@endsection
