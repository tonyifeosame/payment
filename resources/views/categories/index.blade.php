<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Categories</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">

    <!-- Navbar -->
    <nav class="bg-blue-600 p-4 text-white">
        <div class="flex items-center justify-between max-w-6xl mx-auto">
            <div class="flex gap-6">
                @isset($school)
                    <a href="{{ route('school.categories.index', ['school' => $school->slug]) }}" class="hover:underline">Categories</a>
                    <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" class="hover:underline">Subcategories</a>
                    <a href="{{ route('school.transactions.index', ['school' => $school->slug]) }}" class="hover:underline">Transactions</a>
                @else
                    <a href="{{ route('categories.index') }}" class="hover:underline">Categories</a>
                    <a href="{{ route('subcategories.index') }}" class="hover:underline">Subcategories</a>
                    <a href="{{ route('transactions.index') }}" class="hover:underline">Transactions</a>
                @endisset
            </div>
            <div>
                <a href="{{ route('contact.show') }}" class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/20 text-white px-3 py-1.5 rounded-md">Contact</a>
            </div>
        </div>
    </nav>

    <div class="max-w-5xl mx-auto mt-8">
        <h1 class="text-3xl font-bold mb-6">Categories</h1>

        <!-- Success message -->
        @if(session('success'))
            <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        <!-- Add Category -->
        <div class="bg-white p-6 rounded-lg shadow mb-6">
            <h2 class="text-xl font-semibold mb-4">Add Category</h2>
            <form method="POST" action="@isset($school){{ route('school.categories.store', ['school' => $school->slug]) }}@else{{ route('categories.store') }}@endisset" class="space-y-4">
                @csrf
                <input type="text" name="name" placeholder="Category Name"
                       class="w-full border rounded p-2 focus:outline-none focus:ring focus:ring-blue-300">

                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    Add Category
                </button>
            </form>
        </div>

        <!-- Categories Table -->
        <div class="overflow-x-auto bg-white rounded-lg shadow">
            <table class="min-w-full border border-gray-200">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border">#</th>
                        <th class="p-3 border">Name</th>
                        <th class="p-3 border">Created At</th>
                        <th class="p-3 border">Updated At</th>
                        <th class="p-3 border">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($categories as $category)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 border">{{ $category->id }}</td>
                            <td class="p-3 border">{{ $category->name }}</td>
                            <td class="p-3 border">{{ $category->created_at->format('Y-m-d H:i') }}</td>
                            <td class="p-3 border">{{ $category->updated_at->format('Y-m-d H:i') }}</td>
                            <td class="p-3 border">
                                <form action="{{ route('categories.destroy', $category->id) }}" method="POST"
                                      onsubmit="return confirm('Are you sure you want to delete this category?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-3 text-center text-gray-500">No categories found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
