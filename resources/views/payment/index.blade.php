<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Fees Payment</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    animation: {
                        'fade-in': 'fadeIn 0.8s ease-out',
                        'slide-up': 'slideUp 0.6s cubic-bezier(0.34, 1.56, 0.64, 1)',
                        'float': 'float 6s ease-in-out infinite',
                    }
                }
            }
        }
    </script>
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        .gradient-border {
            position: relative;
            background: white;
            border-radius: 1rem;
        }
        .gradient-border::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 1rem;
            padding: 2px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6, #10b981);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            z-index: -1;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-indigo-50 via-purple-50 to-teal-50 relative overflow-x-hidden">
    
    <!-- Animated Background Elements -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-20 left-10 w-72 h-72 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-float"></div>
        <div class="absolute top-40 right-10 w-72 h-72 bg-purple-300 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-float" style="animation-delay: 2s;"></div>
        <div class="absolute -bottom-32 left-1/2 w-72 h-72 bg-teal-300 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-float" style="animation-delay: 4s;"></div>
    </div>

    <!-- Header -->
    <header class="relative backdrop-blur-md bg-white/80 border-b border-indigo-100/50 shadow-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="text-center md:text-left">
                    <div class="flex items-center justify-center md:justify-start gap-3 mb-2">
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-600 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                        </div>
                        <h1 class="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight">
                            @isset($school)
                                <span class="bg-gradient-to-r from-blue-600 via-purple-600 to-teal-600 bg-clip-text text-transparent">{{ $school->name }}</span>
                            @else
                                <span class="bg-gradient-to-r from-blue-600 via-purple-600 to-teal-600 bg-clip-text text-transparent">School Portal</span>
                            @endisset
                        </h1>
                    </div>
                    <p class="text-slate-600 font-medium flex items-center justify-center md:justify-start gap-2">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        Secure Payment Gateway
                    </p>
                </div>
                <div class="flex items-center justify-center gap-3 text-sm">
                    <span class="px-4 py-2 bg-gradient-to-r from-blue-100 to-purple-100 text-blue-900 rounded-full font-semibold flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                        SSL Secured
                    </span>
                </div>
            </div>
        </div>
    </header>

    <main class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 lg:py-12">
        <!-- Page Title -->
        <div class="mb-8 text-center lg:text-left animate-fade-in">
            <h2 class="text-3xl lg:text-4xl font-bold text-slate-800 mb-2">Make a Payment</h2>
            <p class="text-slate-600 text-lg">Complete your transaction securely and efficiently</p>
        </div>

        <!-- Flash Messages -->
        @if(session('success'))
            <div class="mb-6 animate-slide-up">
                <div class="bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 rounded-xl p-5 shadow-lg">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <p class="text-green-800 font-semibold">{{ session('success') }}</p>
                    </div>
                </div>
                @if(session('last_transaction_id'))
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <a href="{{ route('payment.receipt', session('last_transaction_id')) }}" 
                           class="inline-flex items-center gap-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white px-6 py-3 rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all duration-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                            View Receipt
                        </a>
                        <a href="{{ route('payment.receipt.download', session('last_transaction_id')) }}" 
                           class="inline-flex items-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-6 py-3 rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all duration-200">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                            </svg>
                            Download Receipt
                        </a>
                    </div>
                @endif
            </div>
        @endif

        @if(session('error'))
            <div class="mb-6 animate-slide-up bg-gradient-to-r from-red-50 to-rose-50 border-l-4 border-red-500 rounded-xl p-5 shadow-lg">
                <div class="flex items-center gap-3">
                    <svg class="w-6 h-6 text-red-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-red-800 font-semibold">{{ session('error') }}</p>
                </div>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 animate-slide-up bg-gradient-to-r from-red-50 to-rose-50 border-l-4 border-red-500 rounded-xl p-5 shadow-lg">
                <div class="flex gap-3">
                    <svg class="w-6 h-6 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <div>
                        <p class="font-bold text-red-900 mb-2">Please fix the following errors:</p>
                        <ul class="list-disc list-inside text-red-800 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
            <!-- Payment Form -->
            <div class="lg:col-span-2 animate-slide-up" style="animation-delay: 0.1s;">
                <div class="gradient-border shadow-2xl p-8 rounded-2xl bg-white">
                    <div class="flex items-center gap-3 mb-6 pb-6 border-b border-slate-200">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-500 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-slate-800">Payment Details</h3>
                            <p class="text-sm text-slate-600">Fill in your information below</p>
                        </div>
                    </div>

                    <form id="paymentForm" action="@isset($school){{ route('school.payment.initialize', ['school' => $school->slug]) }}@else{{ route('payment.initialize') }}@endisset" method="POST" class="space-y-6">
                        @csrf

                        <!-- Personal Info -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="group">
                                <label for="name" class="block text-sm font-bold text-slate-700 mb-2 uppercase tracking-wide">Full Name</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-slate-400 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                    </div>
                                    <input type="text" id="name" name="name" value="{{ old('name') }}" autocomplete="name"
                                           class="w-full pl-12 pr-4 py-3.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all duration-200 font-medium" required placeholder="Enter your full name">
                                </div>
                                @error('name') <span class="text-red-600 text-sm mt-1 flex items-center gap-1"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>{{ $message }}</span> @enderror
                            </div>
                            <div class="group">
                                <label for="email" class="block text-sm font-bold text-slate-700 mb-2 uppercase tracking-wide">Email Address</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-slate-400 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                    <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email"
                                           class="w-full pl-12 pr-4 py-3.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all duration-200 font-medium" required placeholder="your@email.com">
                                </div>
                                @error('email') <span class="text-red-600 text-sm mt-1 flex items-center gap-1"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>{{ $message }}</span> @enderror
                            </div>
                        </div>

                        

                        <!-- Fee Selection -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="group">
                                <label for="category" class="block text-sm font-bold text-slate-700 mb-2 uppercase tracking-wide">Category</label>
                                <div class="relative">
                                    <select name="category_id" id="category" class="w-full px-4 py-3.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all duration-200 font-medium appearance-none bg-white" required>
                                        <option value="">-- Select Category --</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>
                                                {{ $category->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                                <span id="catError" class="text-red-600 text-sm mt-1"></span>
                                @error('category_id') <span class="text-red-600 text-sm mt-1 flex items-center gap-1"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>{{ $message }}</span> @enderror
                            </div>

                            <div class="group">
                                <label for="subcategory" class="block text-sm font-bold text-slate-700 mb-2 uppercase tracking-wide">Fee Type</label>
                                <div class="relative">
                                    <select name="subcategory_id" id="subcategory" class="w-full px-4 py-3.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all duration-200 font-medium appearance-none bg-white" required disabled>
                                        <option value="">-- Select Fee Type --</option>
                                    </select>
                                    <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                                <p id="unitPrice" class="text-slate-600 text-sm mt-2 font-medium">Unit Price: ₦0</p>
                                <span id="subError" class="text-red-600 text-sm mt-1"></span>
                                @error('subcategory_id') <span class="text-red-600 text-sm mt-1 flex items-center gap-1"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>{{ $message }}</span> @enderror
                            </div>

                            <div id="quantityContainer" class="group">
                                <label for="quantity" class="block text-sm font-bold text-slate-700 mb-2 uppercase tracking-wide">Quantity</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-slate-400 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                                        </svg>
                                    </div>
                                    <input type="number" name="quantity" id="quantity" value="{{ old('quantity', 1) }}" min="1"
                                           class="w-full pl-12 pr-4 py-3.5 border-2 border-slate-200 rounded-xl focus:border-blue-500 focus:ring-4 focus:ring-blue-100 transition-all duration-200 font-medium" required>
                                </div>
                                <span id="qtyError" class="text-red-600 text-sm mt-1"></span>
                                @error('quantity') <span class="text-red-600 text-sm mt-1 flex items-center gap-1"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <!-- Total -->
                        <div class="group">
                            <label for="total" class="block text-sm font-bold text-slate-700 mb-2 uppercase tracking-wide">Total Amount (incl. service fee)</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <input type="text" id="total" name="total" class="w-full pl-12 pr-4 py-3.5 border-2 border-slate-200 rounded-xl bg-slate-50 font-bold text-lg" readonly>
                            </div>
                        </div>

                        <!-- Hidden fields -->
                        <input type="hidden" name="category_name" id="category_name">
                        <input type="hidden" name="subcategory_name" id="subcategory_name">
                        <input type="hidden" name="client_total" id="client_total">

                        <!-- Submit Button -->
                        <div class="pt-4">
                        <button id="submitBtn" type="submit" class="group w-full relative overflow-hidden bg-gradient-to-r from-blue-600 via-purple-600 to-teal-600 hover:from-blue-500 hover:via-purple-500 hover:to-teal-500 text-white py-3.5 px-4 rounded-xl font-extrabold tracking-wide shadow-lg hover:shadow-xl transition-all duration-200">
                                <span class="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></span>
                                <svg id="spinner" class="hidden h-5 w-5 animate-spin mr-2 inline-block align-middle" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                                <span id="submitText" class="align-middle">Proceed to Pay</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Order Summary -->
            <aside class="lg:col-span-1 animate-slide-up" style="animation-delay: 0.2s;">
                <div class="bg-white rounded-2xl shadow-lg border border-slate-200 p-6 sticky top-24">
                    <h3 class="text-lg font-extrabold text-slate-900">Order Summary</h3>
                    <div class="mt-4 space-y-3 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-600">Category</span>
                            <span id="summaryCategory" class="font-semibold text-slate-900">—</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-600">Fee</span>
                            <span id="summarySub" class="font-semibold text-slate-900">—</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-600">Unit Price</span>
                            <span id="summaryPrice" class="font-semibold text-slate-900">₦0</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-600">Service Fee ({{ isset($markupPercent) ? $markupPercent : 0 }}%)</span>
                            <span id="summaryFee" class="font-semibold text-slate-900">₦0</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-600">Quantity</span>
                            <span id="summaryQty" class="font-semibold text-slate-900">1</span>
                        </div>
                        <hr class="my-3">
                        <div class="flex items-center justify-between text-base">
                            <span class="font-extrabold text-slate-800">Total</span>
                            <span id="summaryTotal" class="font-black text-slate-900">₦0</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <!-- Debug Panel (optional for support) -->
        
    </main>

    <script>
    // Cache DOM elements
    const catSelect = document.getElementById('category');
    const subSelect = document.getElementById('subcategory');
    const qtyInput = document.getElementById('quantity');
    const qtyContainer = document.getElementById('quantityContainer');
    const totalField = document.getElementById('total');
    const catNameInput = document.getElementById('category_name');
    const subNameInput = document.getElementById('subcategory_name');
    const sCategory = document.getElementById('summaryCategory');
    const sSub = document.getElementById('summarySub');
    const sPrice = document.getElementById('summaryPrice');
    const sQty = document.getElementById('summaryQty');
    const sTotal = document.getElementById('summaryTotal');
    const sFee = document.getElementById('summaryFee');
    const submitBtn = document.getElementById('submitBtn');
    const spinner = document.getElementById('spinner');
    const submitText = document.getElementById('submitText');
    const catError = document.getElementById('catError');
    const subError = document.getElementById('subError');
    const qtyError = document.getElementById('qtyError');
    const unitPriceP = document.getElementById('unitPrice');
    const clientTotalInput = document.getElementById('client_total');

    // Old values from server (if any)
    const oldCategoryId = {!! json_encode(old('category_id')) !!};
    const oldSubcategoryId = {!! json_encode(old('subcategory_id')) !!};

    // Pre-sanitized structure from controller for reliability
    const categories = {!! json_encode($categoriesForJs) !!} || [];

    // Listeners
    catSelect.addEventListener('change', function () {
        try {
            populateSubcategories();
            updateTotal();
        } catch (e) { console.error('Error on category change:', e); }
    });
    subSelect.addEventListener('change', function () {
        try { updateTotal(); } catch (e) { console.error('Error on subcategory change:', e); }
    });
    document.addEventListener('input', function (event) {
        if (event.target === qtyInput) updateTotal();
    });

    // Update total and summary
    function updateTotal() {
        const selectedCatOption = catSelect.options[catSelect.selectedIndex];
        const selectedOption = subSelect.options[subSelect.selectedIndex];
        const price = Number(selectedOption?.getAttribute('data-price')) || 0;

        // Enforce single quantity for School Fees
        const catNameText = (selectedCatOption ? selectedCatOption.textContent : '').toLowerCase();
        const isSchoolFees = catNameText.includes('school fee');
        if (isSchoolFees) { qtyInput.value = 1; }
        const qty = Math.max(1, Number(qtyInput.value) || 1);
        const base = price * qty;
        const markupPercent = Number({{ isset($markupPercent) ? $markupPercent : 0 }});
        const fee = Math.round((base * (markupPercent/100)) * 100) / 100;
        const total = base + fee;
        const catName = selectedCatOption ? selectedCatOption.textContent : '';
        const subName = selectedOption?.getAttribute('data-subname') || (selectedOption ? selectedOption.textContent.trim() : '');

        // Hidden inputs
        catNameInput.value = catName;
        subNameInput.value = subName;

        // Summary UI
        sCategory.textContent = catName || '—';
        sSub.textContent = subName || '—';
        sPrice.textContent = toCurrency(price);
        sQty.textContent = String(qty);
        sTotal.textContent = toCurrency(total);
        sFee.textContent = toCurrency(fee);

        totalField.value = total ? total.toLocaleString() : '';
        unitPriceP.textContent = 'Unit Price: ' + toCurrency(price);
        clientTotalInput.value = String(total);

        // Basic inline validation
        catError.textContent = !catSelect.value ? 'Please select a category' : '';
        subError.textContent = !subSelect.value ? 'Please select a fee type' : '';
        qtyError.textContent = (Number(qtyInput.value) || 0) < 1 ? 'Quantity must be at least 1' : '';
        toggleQuantityVisibility();
    }

    // Helpers
    function toCurrency(n) { return '₦' + (Number(n) || 0).toLocaleString(); }

    function populateSubcategories() {
        // Clear current options
        while (subSelect.firstChild) subSelect.removeChild(subSelect.firstChild);
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select Fee Type --';
        subSelect.appendChild(placeholder);

        const catId = Number(catSelect.value || 0);
        if (!catId) { subSelect.disabled = true; unitPriceP.textContent = 'Unit Price: ₦0'; return; }

        const cat = (categories || []).find(c => Number(c.id) === catId);
        const subs = (cat && cat.subcategories) ? cat.subcategories : [];
        if (subs.length === 0) { subSelect.disabled = true; unitPriceP.textContent = 'Unit Price: ₦0'; return; }

        subs.forEach(sub => {
            const opt = document.createElement('option');
            opt.value = sub.id;
            opt.textContent = `${sub.name} (₦${Number(sub.price || 0).toLocaleString()})`;
            opt.setAttribute('data-price', Number(sub.price || 0));
            opt.setAttribute('data-subname', sub.name);
            subSelect.appendChild(opt);
        });
        subSelect.disabled = false;

        if (oldSubcategoryId && subs.some(s => String(s.id) === String(oldSubcategoryId))) {
            subSelect.value = String(oldSubcategoryId);
        } else if (subs.length > 0) {
            subSelect.value = String(subs[0].id);
        }

        updateTotal();
    }

    function toggleQuantityVisibility() {
        const selectedCatOption = catSelect.options[catSelect.selectedIndex];
        const catNameText = (selectedCatOption ? selectedCatOption.textContent : '').toLowerCase();
        const isSchoolFees = catNameText.includes('school fee');
        if (isSchoolFees) {
            qtyInput.value = 1;
            qtyInput.setAttribute('disabled', 'disabled');
            qtyContainer.classList.add('hidden');
        } else {
            qtyInput.removeAttribute('disabled');
            qtyContainer.classList.remove('hidden');
        }
    }

    // Initialize on load
    try {
        if (oldCategoryId && (categories || []).some(c => String(c.id) === String(oldCategoryId))) {
            catSelect.value = String(oldCategoryId);
        } else if ((categories || []).length > 0) {
            catSelect.value = String(categories[0].id);
        }
        populateSubcategories();
        updateTotal();
    } catch (e) {
        console.error('Initialization error:', e);
    }

</script>

</body>
</html>