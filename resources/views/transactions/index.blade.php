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
        @isset($school)
            <a href="{{ route('school.categories.index', ['school' => $school->slug]) }}" class="hover:underline">Categories</a>
            <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" class="hover:underline">Subcategories</a>
            <a href="{{ route('school.transactions.index', ['school' => $school->slug]) }}" class="hover:underline font-bold">Transactions</a>
        @else
            <a href="{{ route('categories.index') }}" class="hover:underline">Categories</a>
            <a href="{{ route('subcategories.index') }}" class="hover:underline">Subcategories</a>
            <a href="{{ route('transactions.index') }}" class="hover:underline font-bold">Transactions</a>
        @endisset
    </nav>

    <div class="max-w-7xl mx-auto mt-8">
        <h1 class="text-3xl font-bold mb-6">Transactions</h1>

        <!-- Success message -->
        @if(session('success'))
            <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        <!-- Search -->
        <form method="GET" action="{{ route('transactions.index') }}" class="mb-4">
            <div class="flex items-center gap-2">
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search by student name" class="w-full md:w-80 rounded border border-gray-300 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Search</button>
                @if(!empty($q))
                    <a href="{{ route('transactions.index') }}" class="text-gray-600 hover:text-gray-800">Clear</a>
                @endif
            </div>
        </form>

        <!-- Transactions Table -->
        <div class="overflow-x-auto bg-white rounded-lg shadow">
            <table class="min-w-full border border-gray-200">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border">ID</th>
                        <th class="p-3 border">Paystack Ref</th>
                        <th class="p-3 border">Amount</th>
                        <th class="p-3 border">Status</th>
                        <th class="p-3 border">Payment Method</th>
                        <th class="p-3 border">Email</th>
                        <th class="p-3 border">Name</th>
                        <th class="p-3 border">Category</th>
                        <th class="p-3 border">Subcategory</th>
                        <th class="p-3 border">Meta Data</th>
                        <th class="p-3 border">Created At</th>
                        <th class="p-3 border">Updated At</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 border">{{ $transaction->id }}</td>
                            <td class="p-3 border font-mono text-sm" title="{{ $transaction->paystack_reference }}">
                                {{ $transaction->paystack_reference ? substr($transaction->paystack_reference, 0, 8) . '...' : '—' }}
                            </td>
                            @php
                                $baseAmount = is_array($transaction->meta_data ?? null)
                                    ? ($transaction->meta_data['base_amount'] ?? $transaction->amount)
                                    : (is_string($transaction->meta_data ?? null) ? (json_decode($transaction->meta_data, true)['base_amount'] ?? $transaction->amount) : $transaction->amount);
                            @endphp
                            <td class="p-3 border">{{ number_format($baseAmount, 2) }}</td>
                            <td class="p-3 border">
                                <span class="px-2 py-1 rounded text-white 
                                    {{ $transaction->status === 'success' ? 'bg-green-600' : ($transaction->status === 'pending' ? 'bg-yellow-500' : 'bg-red-600') }}">
                                    {{ ucfirst($transaction->status) }}
                                </span>
                            </td>

                            <!-- ✅ Payment Method from Paystack -->
                            <td class="p-3 border">
                                {{ $transaction->payment_method ? ucfirst($transaction->payment_method) : 'N/A' }}
                            </td>

                            <td class="p-3 border">{{ $transaction->email }}</td>
                            <td class="p-3 border">{{ $transaction->name }}</td>

                            <!-- ✅ Show category_name instead of ID -->
                            <td class="p-3 border">{{ $transaction->category_name ?? 'N/A' }}</td>
                            <td class="p-3 border">{{ $transaction->subcategory_name ?? 'N/A' }}</td>

                            <!-- ✅ Neatly show meta_data -->
                            <td class="p-3 border">
                                @if(is_array($transaction->meta_data))
                                    <ul class="list-disc list-inside text-sm text-gray-700">
                                        @foreach($transaction->meta_data as $key => $value)
                                            <li><strong>{{ ucfirst($key) }}:</strong> {{ $value }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    {{ $transaction->meta_data }}
                                @endif
                            </td>

                            <td class="p-3 border">{{ $transaction->created_at->format('Y-m-d H:i') }}</td>
                            <td class="p-3 border">{{ $transaction->updated_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="p-3 text-center text-gray-500">No transactions found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $transactions->appends(['q' => $q ?? null])->links() }}
        </div>
    </div>
</body>
</html>
