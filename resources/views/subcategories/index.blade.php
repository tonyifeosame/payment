@extends('layouts.admin')

@section('title', 'Fee types')
@section('heading', 'Fee types')
@section('subheading', 'The individual fees parents can pay, with their prices and the term they apply to.')
@section('actions')
    <a href="{{ route('school.subcategories.create', ['school' => $school->slug]) }}" class="btn-primary">+ Add fee type</a>
@endsection

@section('content')
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="th">#</th>
                    <th class="th">Category</th>
                    <th class="th">Fee type</th>
                    <th class="th">Term</th>
                    <th class="th text-right">Price</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse($subcategories as $sub)
                    <tr class="hover:bg-blue-50/50">
                        <td class="td font-bold">{{ $loop->iteration }}</td>
                        <td class="td"><span class="badge bg-blue-100 text-blue-800">{{ $sub->category->name ?? 'N/A' }}</span></td>
                        <td class="td font-semibold">{{ $sub->name }}</td>
                        <td class="td">
                            @if($sub->academicTerm)
                                {{ $sub->academicTerm->name }}, {{ $sub->academicTerm->session?->name }}
                            @else
                                <span class="text-slate-500">General (any term)</span>
                            @endif
                        </td>
                        <td class="td text-right font-bold whitespace-nowrap">₦{{ number_format((float) $sub->price, 2) }}</td>
                        <td class="td text-right whitespace-nowrap">
                            <a href="{{ route('school.subcategories.edit', ['school' => $school->slug, 'subcategory' => $sub->id]) }}" class="btn-secondary !py-1.5 !px-3">Edit</a>
                            <form action="{{ route('school.subcategories.destroy', ['school' => $school->slug, 'subcategory' => $sub->id]) }}" method="POST" class="inline" onsubmit="return confirm('Delete this fee type?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger !py-1.5 !px-3">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500"><p class="font-bold text-slate-800">No fee types yet</p><p class="text-sm">Add a fee type under one of your categories.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
