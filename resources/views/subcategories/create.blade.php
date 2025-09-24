@extends('layouts.app')

@section('content')
<div class="max-w-2xl mx-auto bg-white p-6 shadow-md rounded">
    <h1 class="text-2xl font-bold mb-4">Add Subcategory</h1>

    <!-- Show validation errors -->
    @if ($errors->any())
        <div class="bg-red-100 text-red-700 p-3 rounded mb-4">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('subcategories.store') }}" method="POST" class="space-y-4">
        @csrf

        <!-- Category dropdown -->
        <div>
            <label class="block text-sm font-medium mb-1">Category</label>
            <select name="category_id" class="w-full border p-2 rounded" required>
                <option value="">-- Select Category --</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>

        <!-- Subcategory name -->
        <div>
            <label class="block text-sm font-medium mb-1">Subcategory Name</label>
            <input type="text" name="name" class="w-full border p-2 rounded" required>
        </div>

        <!-- Price -->
        <div>
            <label class="block text-sm font-medium mb-1">Price</label>
            <input type="number" step="0.01" name="price" class="w-full border p-2 rounded">
        </div>

        <div class="flex space-x-2">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Save</button>
            <a href="{{ route('subcategories.index') }}" class="bg-gray-500 text-white px-4 py-2 rounded">Cancel</a>
        </div>
    </form>
</div>
@endsection
