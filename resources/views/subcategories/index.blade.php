<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subcategories</title>
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
                               class="px-4 py-2 rounded-lg bg-white/20 text-white font-bold backdrop-blur-sm">
                                Subcategories
                            </a>
                            <a href="{{ route('school.transactions.index', ['school' => $school->slug]) }}" 
                               class="px-4 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all duration-200 font-medium">
                                Transactions
                            </a>
                        @else
                            <a href="{{ route('categories.index') }}" 
                               class="px-4 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all duration-200 font-medium">
                                Categories
                            </a>
                            <a href="{{ route('subcategories.index') }}" 
                               class="px-4 py-2 rounded-lg bg-white/20 text-white font-bold backdrop-blur-sm">
                                Subcategories
                            </a>
                            <a href="{{ route('transactions.index') }}" 
                               class="px-4 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all duration-200 font-medium">
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
                        Subcategories
                    </h1>
                    <p class="text-slate-600 font-medium">Manage detailed payment subcategories</p>
                </div>
                <a href="@isset($school){{ route('school.subcategories.create', ['school' => $school->slug]) }}@else{{ route('subcategories.create') }}@endisset"
                   class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all duration-200">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                    Add Subcategory
                </a>
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

        <!-- Subcategories Table Card -->
        <div class="glass-effect rounded-2xl shadow-2xl overflow-hidden border border-slate-200 animate-fade-in" style="animation-delay: 0.2s;">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-gradient-to-r from-slate-50 to-slate-100">
                        <tr>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">#</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Category</th>
                            <th class="px-4 py-4 text-left text-xs font-black text-slate-700 uppercase tracking-wider">Name</th>
                            <th class="px-4 py-4 text-right text-xs font-black text-slate-700 uppercase tracking-wider">Price</th>
                            <th class="px-4 py-4 text-right text-xs font-black text-slate-700 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse($subcategories as $sub)
                            <tr class="table-row-hover">
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="text-sm font-bold text-slate-900">{{ $loop->iteration }}</span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg bg-blue-100 text-blue-800 text-xs font-bold">
                                        {{ $sub->category->name ?? 'N/A' }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <span class="text-sm font-medium text-slate-800">{{ $sub->name }}</span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right">
                                    <span class="text-sm font-bold text-slate-900">₦{{ number_format($sub->price, 2) }}</span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="@isset($school){{ route('school.subcategories.edit', ['school' => $school->slug, 'subcategory' => $sub->id]) }}@else{{ route('subcategories.edit', $sub->id) }}@endisset" class="inline-flex items-center gap-1 bg-yellow-100 text-yellow-800 px-3 py-1.5 rounded-md hover:bg-yellow-200 text-xs font-bold">
                                            Edit
                                        </a>
                                        <form action="@isset($school){{ route('school.subcategories.destroy', ['school' => $school->slug, 'subcategory' => $sub->id]) }}@else{{ route('subcategories.destroy', $sub->id) }}@endisset" method="POST" onsubmit="return confirm('Are you sure you want to delete this subcategory?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex items-center gap-1 bg-red-100 text-red-800 px-3 py-1.5 rounded-md hover:bg-red-200 text-xs font-bold">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-12">
                                    <div class="flex flex-col items-center justify-center text-center">
                                        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mb-4">
                                            <svg class="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                            </svg>
                                        </div>
                                        <h3 class="text-lg font-bold text-slate-900 mb-1">No subcategories found</h3>
                                        <p class="text-slate-500">Add a new subcategory to get started.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>