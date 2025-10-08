@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center">
        <h1 class="text-3xl font-bold text-slate-800">Welcome</h1>
        <p class="text-slate-600 mt-2">Start by creating a registration before proceeding to payment.</p>

        <a href="{{ route('registration.create') }}" class="inline-flex items-center justify-center mt-6 px-5 py-3 rounded-md bg-blue-600 text-white hover:bg-blue-700">
            Create Payment
        </a>
    </div>
</div>
@endsection
