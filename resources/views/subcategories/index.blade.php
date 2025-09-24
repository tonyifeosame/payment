<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subcategories</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">

    <!-- Navbar -->
    <nav class="bg-blue-600 p-4 text-white flex space-x-6 shadow">
        <a href="{{ route('categories.index') }}" class="hover:underline">Categories</a>
        <a href="{{ route('subcategories.index') }}" class="hover:underline font-bold">Subcategories</a>
        <a href="{{ route('transactions.index') }}" class="hover:underline">Transactions</a>
    </nav>

    <div class="max-w-6xl mx-auto p-6">
        <h1 class="text-3xl font-bold mb-6">Subcategories</h1>

        <!-- Add Subcategory Button -->
        <div class="mb-6">
            <a href="{{ route('subcategories.create') }}" 
               class="bg-blue-600 text-white px-4 py-2 rounded-lg shadow hover:bg-blue-700 transition">
                + Add Subcategory
            </a>
        </div>

        <!-- Flash message -->
        @if(session('success'))
            <div class="mb-4 p-3 bg-green-100 text-green-700 rounded">
                {{ session('success') }}
            </div>
        @endif

        <!-- Subcategories Table -->
        <div class="overflow-x-auto bg-white rounded-lg shadow">
            <table class="min-w-full border border-gray-200">
                <thead>
                    <tr class="bg-gray-100 text-left text-gray-700">
                        <th class="p-3 border">#</th>
                        <th class="p-3 border">Category</th>
                        <th class="p-3 border">Name</th>
                        <th class="p-3 border">Price</th>
                        <th class="p-3 border">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subcategories as $sub)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 border">{{ $sub->id }}</td>
                            <td class="p-3 border">{{ $sub->category->name ?? 'N/A' }}</td>
                            <td class="p-3 border">{{ $sub->name }}</td>
                            <td class="p-3 border">₦{{ number_format($sub->price, 2) }}</td>
                            <td class="p-3 border">
                                <form action="{{ route('subcategories.destroy', $sub->id) }}" method="POST" 
                                      onsubmit="return confirm('Are you sure you want to delete this subcategory?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" 
                                            class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700 transition">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-3 text-center text-gray-500">No subcategories found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>
