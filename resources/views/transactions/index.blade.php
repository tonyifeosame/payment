<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Transactions</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">

    <div class="max-w-7xl mx-auto mt-8">
        <h1 class="text-3xl font-bold mb-6">Transactions</h1>

        <!-- Success message -->
        @if(session('success'))
            <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        <!-- Transactions Table -->
        <div class="overflow-x-auto bg-white rounded-lg shadow">
            <table class="min-w-full border border-gray-200">
                <thead class="bg-gray-100 text-gray-700">
                    <tr>
                        <th class="p-3 border">ID</th>
                        <th class="p-3 border">Admission No.</th>
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
                            <td class="p-3 border">{{ $transaction->admission_number }}</td>
                            <td class="p-3 border">{{ number_format($transaction->amount, 2) }}</td>
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
            {{ $transactions->links() }}
        </div>
    </div>
</body>
</html>
