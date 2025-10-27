<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transactions Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        .animate-slide-in {
            animation: slideIn 0.5s ease-out;
        }
        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .glass-effect {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        .status-badge {
            @apply inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wide;
        }
        .table-row-hover {
            @apply transition-all duration-200 hover:bg-blue-50 hover:shadow-md;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-blue-50 to-purple-50 min-h-screen">
    <!-- Modern Navbar -->
    <nav class="gradient-bg shadow-xl sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo & Navigation -->
                <div class="flex items-center space-x-8">
                    <div class="flex items-center gap-2">
                        <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                        </div>
                        <span class="text-white font-bold text-lg hidden sm:block">School Portal</span>
                    </div>
                    
                    <div class="hidden md:flex space-x-1">
                        @isset($school)
                            <a href="{{ route('school.categories.index', ['school' => $school->slug]) }}" 
                               class="px-4 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all duration-200 font-medium">
                                Categories
                            </a>
                            <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" 
                               class="px-4 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all duration-200 font-medium">
                                Subcategories
                            </a>
                            <a href="{{ route('school.transactions.index', ['school' => $school->slug]) }}" 
                               class="px-4 py-2 rounded-lg bg-white/20 text-white font-bold backdrop-blur-sm">
                                Transactions
                            </a>
                        @else
                            <a href="{{ route('categories.index') }}" 
                               class="px-4 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all duration-200 font-medium">
                                Categories
                            </a>
                            <a href="{{ route('subcategories.index') }}" 
                               class="px-4 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all duration-200 font-medium">
                                Subcategories
                            </a>
                            <a href="{{ route('transactions.index') }}" 
                               class="px-4 py-2 rounded-lg bg-white/20 text-white font-bold backdrop-blur-sm">
                                Transactions
                            </a>
                        @endisset
                    </div>
                </div>

                <!-- Contact Button -->
                <a href="{{ route('contact.show') }}" 
                   class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/20 text-white px-4 py-2 rounded-lg font-medium transition-all duration-200 backdrop-blur-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                    <span class="hidden sm:inline">Contact</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Header Section -->
        <div class="mb-8 animate-fade-in">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-4xl font-black text-slate-900 mb-2 bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                        Transactions
                    </h1>
                    <p class="text-slate-600 font-medium">Track and manage all payment transactions</p>
                </div>
                <div class="hidden sm:flex items-center gap-3">
                    <div class="px-4 py-2 bg-white rounded-xl shadow-sm border border-slate-200">
                        <span class="text-slate-500 text-sm font-medium">Total: </span>
                        <span class="text-slate-900 font-bold">{{ $transactions->total() }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success Message -->
        @if(session('success'))
            <div class="mb-6 animate-slide-in">
                <div class="glass-effect rounded-xl border-2 border-green-200 p-4 shadow-lg">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-green-500 rounded-xl flex items-center justify-center shadow-md">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <p class="text-green-800 font-bold">{{ session('success') }}</p>
                    </div>
                </div>
            </div>
        @endif

        <!-- Search and Filters Card -->
        <div class="glass-effect rounded-2xl shadow-xl p-6 mb-6 border border-slate-200 animate-fade-in" style="animation-delay: 0.1s;">
            <form method="GET" action="{{ route('transactions.index') }}">
                <div class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Search Transactions</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <input type="text" name="q" value="{{ $q ?? '' }}" 
                                   placeholder="Search by student name, email, or reference..." 
                                   class="w-full pl-11 pr-4 py-3 rounded-lg border-2 border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-50 transition-all duration-200 font-medium" />
                        </div>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" 
                                class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all duration-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            Search
                        </button>
                        @if(!empty($q))
                            <a href="{{ route('transactions.index') }}" 
                               class="inline-flex items-center gap-2 px-6 py-3 rounded-lg border-2 border-slate-300 text-slate-700 hover:bg-slate-50 font-bold transition-all duration-200">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                                Clear
                            </a>
                        @endif
                    </div>
                </div>
            </form>
        </div>

        <!-- Transactions Table Card -->
        <div class="glass-effect rounded-2xl shadow-2xl overflow-hidden border border-slate-200 animate-fade-in" style="animation-delay: 0.2s;">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-gradient-to-r from-slate-50 to-slate-100">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">#</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Reference</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Amount</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Method</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Student</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Category</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Subcategory</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Qty</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse($transactions as $transaction)
                            <tr class="table-row-hover">
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="text-sm font-bold text-slate-900">
                                        {{ ($transactions->currentPage() - 1) * $transactions->perPage() + $loop->iteration }}
                                    </span>
                                </td>
                                
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <code class="text-xs font-mono bg-slate-100 px-2 py-1 rounded text-slate-700" title="{{ $transaction->paystack_reference }}">
                                            {{ $transaction->paystack_reference ? substr($transaction->paystack_reference, 0, 10) . '...' : '—' }}
                                        </code>
                                    </div>
                                </td>

                                <td class="px-4 py-4 whitespace-nowrap">
                                    @php
                                        $baseAmount = is_array($transaction->meta_data ?? null)
                                            ? ($transaction->meta_data['base_amount'] ?? $transaction->amount)
                                            : (is_string($transaction->meta_data ?? null) ? (json_decode($transaction->meta_data, true)['base_amount'] ?? $transaction->amount) : $transaction->amount);
                                    @endphp
                                    <span class="text-sm font-bold text-slate-900">₦{{ number_format($baseAmount, 2) }}</span>
                                </td>

                                <td class="px-4 py-4 whitespace-nowrap">
                                    @if($transaction->status === 'success')
                                        <span class="status-badge bg-green-100 text-green-800">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                            </svg>
                                            Success
                                        </span>
                                    @elseif($transaction->status === 'pending')
                                        <span class="status-badge bg-yellow-100 text-yellow-800">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                                            </svg>
                                            Pending
                                        </span>
                                    @else
                                        <span class="status-badge bg-red-100 text-red-800">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                            </svg>
                                            Failed
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        @if($transaction->payment_method === 'card')
                                            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                            </svg>
                                        @elseif($transaction->payment_method === 'bank')
                                            <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                                            </svg>
                                        @else
                                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        @endif
                                        <span class="text-sm font-medium text-slate-700">
                                            {{ $transaction->payment_method ? ucfirst($transaction->payment_method) : 'N/A' }}
                                        </span>
                                    </div>
                                </td>

                                <td class="px-4 py-4">
                                    <div class="flex flex-col">
                                        <span class="text-sm font-bold text-slate-900">{{ $transaction->name }}</span>
                                        <span class="text-xs text-slate-500">{{ $transaction->email }}</span>
                                    </div>
                                </td>

                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-blue-100 text-blue-800 text-xs font-bold">
                                        {{ $transaction->category_name ?? 'N/A' }}
                                    </span>
                                </td>

                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-purple-100 text-purple-800 text-xs font-bold">
                                        {{ $transaction->subcategory_name ?? 'N/A' }}
                                    </span>
                                </td>

                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    @php
                                        $metaArr = is_array($transaction->meta_data) ? $transaction->meta_data : (is_string($transaction->meta_data) ? (json_decode($transaction->meta_data, true) ?: []) : []);
                                        $qty = (int) ($metaArr['quantity'] ?? 1);
                                    @endphp
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-900 text-sm font-bold">
                                        {{ $qty }}
                                    </span>
                                </td>

                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium text-slate-900">{{ $transaction->created_at->format('M d, Y') }}</span>
                                        <span class="text-xs text-slate-500">{{ $transaction->created_at->format('h:i A') }}</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-12">
                                    <div class="flex flex-col items-center justify-center text-center">
                                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mb-4">
                                            <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                            </svg>
                                        </div>
                                        <h3 class="text-lg font-bold text-slate-900 mb-1">No transactions found</h3>
                                        <p class="text-slate-500">Try adjusting your search criteria</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($transactions->hasPages())
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-slate-600">
                            Showing <span class="font-bold">{{ $transactions->firstItem() }}</span> to 
                            <span class="font-bold">{{ $transactions->lastItem() }}</span> of 
                            <span class="font-bold">{{ $transactions->total() }}</span> results
                        </div>
                        <div class="flex gap-1">
                            {{ $transactions->appends(['q' => $q ?? null])->links() }}
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-8 animate-fade-in" style="animation-delay: 0.3s;">
            <div class="glass-effect rounded-2xl p-6 border border-slate-200 shadow-lg">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-slate-500 text-sm font-medium">Successful</p>
                        <p class="text-2xl font-black text-slate-900">{{ $transactions->where('status', 'success')->count() }}</p>
                    </div>
                </div>
            </div>

            <div class="glass-effect rounded-2xl p-6 border border-slate-200 shadow-lg">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-yellow-500 to-orange-600 rounded-xl flex items-center justify-center shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-slate-500 text-sm font-medium">Pending</p>
                        <p class="text-2xl font-black text-slate-900">{{ $transactions->where('status', 'pending')->count() }}</p>
                    </div>
                </div>
            </div>

            <div class="glass-effect rounded-2xl p-6 border border-slate-200 shadow-lg">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-red-500 to-rose-600 rounded-xl flex items-center justify-center shadow-lg">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-slate-500 text-sm font-medium">Failed</p>
                        <p class="text-2xl font-black text-slate-900">{{ $transactions->where('status', 'failed')->count() }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>