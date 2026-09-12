@extends('layouts.admin')

@section('title', 'Categories')
@section('heading', 'Categories')
@section('subheading', 'Group your fees, e.g. School Fees, Uniform, Books. Fee types with prices live under each category.')

@section('content')
<form method="POST" action="{{ route('school.categories.store', ['school' => $school->slug]) }}" class="card p-5 mb-4 flex flex-col md:flex-row gap-3 md:items-end">
    @csrf
    <div class="flex-1">
        <label for="name" class="label">Add new category</label>
        <input id="name" name="name" value="{{ old('name') }}" class="input" placeholder="e.g. School Fees" required maxlength="255">
    </div>
    <button type="submit" class="btn-primary">Add category</button>
</form>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="th">#</th>
                    <th class="th">Name</th>
                    <th class="th">Fee types</th>
                    <th class="th">Created</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
                @forelse($categories as $category)
                    <tr class="hover:bg-blue-50/50">
                        <td class="td font-bold">{{ $loop->iteration }}</td>
                        <td class="td font-semibold">{{ $category->name }}</td>
                        <td class="td">{{ $category->subcategories_count ?? $category->subcategories()->count() }}</td>
                        <td class="td whitespace-nowrap">{{ $category->created_at->format('d M Y') }}</td>
                        <td class="td text-right whitespace-nowrap">
                            <a href="{{ route('school.categories.edit', ['school' => $school->slug, 'category' => $category->id]) }}" class="btn-secondary !py-1.5 !px-3">Edit</a>
                            <form action="{{ route('school.categories.destroy', ['school' => $school->slug, 'category' => $category->id]) }}" method="POST" class="inline" onsubmit="return confirm('Delete this category and all its fee types?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger !py-1.5 !px-3">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-slate-500"><p class="font-bold text-slate-800">No categories found</p><p class="text-sm">Add a new category to get started.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
