<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subcategories</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">

    <!-- Navbar -->
    <nav class="bg-blue-600 p-4 text-white flex space-x-6">
        <a href="{{ url('/categories') }}" class="hover:underline">Categories</a>
        <a href="{{ url('/subcategories') }}" class="hover:underline">Subcategories</a>
        <a href="{{ url('/transactions') }}" class="hover:underline">Transactions</a>
    </nav>

    <div class="container mx-auto mt-8">
        <h1 class="text-2xl font-bold mb-4">Subcategories</h1>
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
    <h2 class="text-xl font-semibold mb-4">Add Subcategory</h2>
    <form method="POST" action="{{ url('/subcategories') }}" class="space-y-4">
        @csrf
        <select name="category_id" class="w-full border rounded p-2">
            <option value="">Select Category</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>

        <input type="text" name="name" placeholder="Subcategory Name"
               class="w-full border rounded p-2 focus:outline-none focus:ring focus:ring-blue-300">

        <input type="number" name="price" placeholder="Price"
               class="w-full border rounded p-2 focus:outline-none focus:ring focus:ring-blue-300">

        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            Add Subcategory
        </button>
    </form>
</div>


        <div class="overflow-x-auto">
            <table class="min-w-full bg-white border border-gray-300 rounded-lg shadow-md">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="py-2 px-4 border">ID</th>
                        <th class="py-2 px-4 border">Category ID</th>
                        <th class="py-2 px-4 border">Name</th>
                        <th class="py-2 px-4 border">Price</th>
                        <th class="py-2 px-4 border">Created At</th>
                        <th class="py-2 px-4 border">Updated At</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($subcategories as $subcategory)
                        <tr class="hover:bg-gray-100">
                            <td class="py-2 px-4 border">{{ $subcategory->id }}</td>
                            <td class="py-2 px-4 border">{{ $subcategory->category_id }}</td>
                            <td class="py-2 px-4 border">{{ $subcategory->name }}</td>
                            <td class="py-2 px-4 border">{{ $subcategory->price }}</td>
                            <td class="py-2 px-4 border">{{ $subcategory->created_at }}</td>
                            <td class="py-2 px-4 border">{{ $subcategory->updated_at }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
