@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto py-8 px-4">
    <h1 class="text-3xl font-bold text-slate-900 mb-6">Categories</h1>
    <div class="bg-white shadow-md rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Price</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-slate-200">
                @foreach ($categories as $cat)
                <tr class="hover:bg-slate-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">{{ $cat->id }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-700">{{ $cat->name }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-700">{{ $cat->price ?? 'N/A' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
