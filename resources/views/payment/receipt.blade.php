<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    animation: {
                        'fade-in': 'fadeIn 0.8s ease-out',
                        'slide-down': 'slideDown 0.6s ease-out',
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
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media print {
            .no-print { display: none; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .print-shadow { box-shadow: none; border: 1px solid #e2e8f0; }
        }
        .gradient-border {
            position: relative;
        }
        .gradient-border::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 1rem;
            padding: 2px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6, #10b981);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-blue-50 to-purple-50 py-8 px-4">
    <div class="max-w-4xl mx-auto animate-fade-in">
        <!-- Success Badge -->
        <div class="text-center mb-6 animate-slide-down">
            <div class="inline-flex items-center gap-3 bg-gradient-to-r from-green-50 to-emerald-50 border-2 border-green-200 rounded-full px-6 py-3 shadow-lg">
                <div class="w-10 h-10 bg-gradient-to-br from-green-500 to-emerald-500 rounded-full flex items-center justify-center shadow-md">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <div class="text-left">
                    <p class="text-sm font-semibold text-green-900">Payment Successful</p>
                    <p class="text-xs text-green-700">Your transaction has been completed</p>
                </div>
            </div>
        </div>

        <div class="bg-white shadow-2xl rounded-2xl overflow-hidden print-shadow border border-slate-200">
            <!-- Header -->
            <div class="relative bg-gradient-to-r from-blue-600 via-purple-600 to-teal-600 px-8 py-8">
                <div class="absolute inset-0 bg-black opacity-5"></div>
                <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="text-center md:text-left">
                        @php
                            $schoolName = optional(optional($transaction->category)->school)->name
                                          ?? optional($transaction->subcategory)->school->name
                                          ?? null;
                        @endphp
                        @if($schoolName)
                            <div class="flex items-center justify-center md:justify-start gap-3 mb-3">
                                <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center border border-white/30">
                                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                    </svg>
                                </div>
                                <div class="text-left">
                                    <h1 class="text-3xl sm:text-4xl font-black text-white tracking-tight">{{ $schoolName }}</h1>
                                    <p class="text-blue-100 text-sm font-medium">Official Payment Receipt</p>
                                </div>
                            </div>
                        @else
                            <h1 class="text-3xl sm:text-4xl font-black text-white mb-2">Payment Receipt</h1>
                        @endif
                        <p class="text-white/90 text-sm font-medium">Thank you for your payment</p>
                    </div>
                    <div class="text-center md:text-right">
                        <div class="inline-block bg-white/20 backdrop-blur-sm border border-white/30 rounded-xl px-5 py-3">
                            <p class="text-xs text-white/80 font-semibold uppercase tracking-wider mb-1">Receipt Date</p>
                            <p class="text-white font-bold text-lg">{{ $transaction->created_at?->format('M d, Y') }}</p>
                            <p class="text-white/90 text-sm">{{ $transaction->created_at?->format('h:i A') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div class="px-8 py-8 space-y-8">
                <!-- Payer & Payment Details -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Payer Info -->
                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl p-6 border border-blue-100">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-lg flex items-center justify-center shadow-md">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <h2 class="text-lg font-bold text-slate-800">Payer Information</h2>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <p class="text-xs text-slate-600 font-semibold uppercase tracking-wider mb-1">Full Name</p>
                                <p class="text-slate-900 font-bold text-lg">{{ $transaction->name ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-600 font-semibold uppercase tracking-wider mb-1">Email Address</p>
                                <p class="text-slate-700 font-medium">{{ $transaction->email ?? '—' }}</p>
                            </div>
                            
                        </div>
                    </div>

                    <!-- Payment Details -->
                    <div class="bg-gradient-to-br from-purple-50 to-pink-50 rounded-xl p-6 border border-purple-100">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-pink-500 rounded-lg flex items-center justify-center shadow-md">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                </svg>
                            </div>
                            <h2 class="text-lg font-bold text-slate-800">Payment Details</h2>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <p class="text-xs text-slate-600 font-semibold uppercase tracking-wider mb-1">Reference Number</p>
                                <p class="text-slate-900 font-mono font-bold text-sm bg-white px-3 py-2 rounded-lg border border-slate-200">{{ $transaction->reference ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-600 font-semibold uppercase tracking-wider mb-1">Payment Method</p>
                                <p class="text-slate-700 font-medium">{{ $transaction->payment_method ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-600 font-semibold uppercase tracking-wider mb-1">Status</p>
                                <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-bold {{ $transaction->status === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200' }}">
                                    <span class="w-2 h-2 rounded-full {{ $transaction->status === 'success' ? 'bg-green-500' : 'bg-red-500' }} animate-pulse"></span>
                                    {{ ucfirst($transaction->status) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transaction Table -->
                <div class="gradient-border rounded-xl overflow-hidden">
                    <div class="bg-white">
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="bg-gradient-to-r from-slate-50 to-slate-100 border-b-2 border-slate-200">
                                        <th class="text-left py-4 px-6 text-slate-700 font-bold text-sm uppercase tracking-wider">Category</th>
                                        <th class="text-left py-4 px-6 text-slate-700 font-bold text-sm uppercase tracking-wider">Fee Type</th>
                                        <th class="text-right py-4 px-6 text-slate-700 font-bold text-sm uppercase tracking-wider">Unit Price</th>
                                        <th class="text-right py-4 px-6 text-slate-700 font-bold text-sm uppercase tracking-wider">Qty</th>
                                        <th class="text-right py-4 px-6 text-slate-700 font-bold text-sm uppercase tracking-wider">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @php
                                        $qty = (int) ($transaction->meta_data['quantity'] ?? 1);
                                        $baseTotal = (float) ($transaction->meta_data['base_amount'] ?? $transaction->amount ?? 0);
                                        $unit = (float) ($baseTotal / max($qty,1));
                                    @endphp
                                    <tr class="hover:bg-slate-50 transition-colors">
                                        <td class="py-5 px-6 text-slate-800 font-semibold">{{ $transaction->category_name ?? optional($transaction->category)->name }}</td>
                                        <td class="py-5 px-6 text-slate-700 font-medium">{{ $transaction->subcategory_name ?? optional($transaction->subcategory)->name }}</td>
                                        <td class="py-5 px-6 text-right text-slate-800 font-semibold">₦{{ number_format($unit, 2) }}</td>
                                        <td class="py-5 px-6 text-right text-slate-800 font-semibold">{{ $qty }}</td>
                                        <td class="py-5 px-6 text-right text-slate-900 font-bold text-lg">₦{{ number_format($baseTotal, 2) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot class="bg-gradient-to-r from-blue-50 to-purple-50 border-t-2 border-slate-200">
                                    <tr>
                                        <td colspan="4" class="py-5 px-6 text-right">
                                            <span class="text-slate-700 font-bold text-lg uppercase tracking-wide">Total Amount</span>
                                        </td>
                                        <td class="py-5 px-6 text-right">
                                            <div class="inline-block bg-gradient-to-r from-blue-600 to-purple-600 text-white px-6 py-3 rounded-xl shadow-lg">
                                                <span class="text-2xl font-black">₦{{ number_format($baseTotal, 2) }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Footer Note -->
                <div class="bg-gradient-to-r from-slate-50 to-blue-50 rounded-xl p-6 border border-slate-200">
                    <div class="flex items-start gap-3">
                        <svg class="w-6 h-6 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-slate-800 mb-1">Important Notice</p>
                            <p class="text-sm text-slate-600">This receipt serves as official proof of payment for the transaction detailed above. Please keep this for your records. For any queries, contact the school administration with your reference number.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="px-8 py-6 bg-gradient-to-r from-slate-50 to-slate-100 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4 no-print">
                <div class="flex flex-wrap items-center justify-center gap-3">
                    <a href="{{ route('payment.receipt.download', $transaction) }}" 
                       class="inline-flex items-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white px-6 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all duration-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        Download PDF
                    </a>
                    <button onclick="window.print()" 
                            class="inline-flex items-center gap-2 bg-gradient-to-r from-slate-700 to-slate-800 hover:from-slate-600 hover:to-slate-700 text-white px-6 py-3 rounded-xl font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all duration-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        Print Receipt
                    </button>
                </div>
                @php
                    $schoolSlug = optional($transaction->school)->slug
                                  ?? optional(optional($transaction->category)->school)->slug
                                  ?? optional(optional($transaction->subcategory)->school)->slug
                                  ?? null;
                @endphp
                @if($schoolSlug)
                    <a href="{{ route('school.payment.index', ['school' => $schoolSlug]) }}" 
                       class="inline-flex items-center gap-2 text-slate-600 hover:text-slate-900 font-semibold transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Payment
                    </a>
                @else
                    <a href="{{ route('payment.index') }}" 
                       class="inline-flex items-center gap-2 text-slate-600 hover:text-slate-900 font-semibold transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back to Payment
                    </a>
                @endif
            </div>
        </div>

        <!-- Watermark for Print -->
        <div class="hidden print:block text-center mt-8 text-slate-400 text-xs">
            <p>Generated on {{ now()->format('F d, Y \a\t h:i A') }}</p>
            <p class="mt-1">This is a computer-generated receipt and does not require a signature.</p>
        </div>
    </div>
</body>
</html>