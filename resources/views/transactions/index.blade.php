<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transactions</title>
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
        <h1 class="text-2xl font-bold mb-4">Transactions</h1>

        <div class="overflow-x-auto">
            <table class="min-w-full bg-white border border-gray-300 rounded-lg shadow-md">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="py-2 px-4 border">ID</th>
                        <th class="py-2 px-4 border">Subcategory ID</th>
                        <th class="py-2 px-4 border">Amount</th>
                        <th class="py-2 px-4 border">Created At</th>
                        <th class="py-2 px-4 border">Updated At</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $transaction)
                        <tr class="hover:bg-gray-100">
                            <td class="py-2 px-4 border">{{ $transaction->id }}</td>
                            <td class="py-2 px-4 border">{{ $transaction->subcategory_id }}</td>
                            <td class="py-2 px-4 border">{{ $transaction->amount }}</td>
                            <td class="py-2 px-4 border">{{ $transaction->created_at }}</td>
                            <td class="py-2 px-4 border">{{ $transaction->updated_at }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
