@extends('layouts.app')

@section('content')
<style>
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes slideInRight {
        from { opacity: 0; transform: translateX(20px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    .animate-fade-in-up {
        animation: fadeInUp 0.6s ease-out;
    }
    .animate-slide-in-right {
        animation: slideInRight 0.6s ease-out;
    }
    .gradient-text {
        background: linear-gradient(135deg, #1e40af 0%, #7c3aed 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .glass-card {
        backdrop-filter: blur(20px);
        background: rgba(255, 255, 255, 0.98);
        border: 1px solid rgba(255, 255, 255, 0.3);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
    }
    .input-modern {
        @apply w-full px-4 py-3 rounded-lg border-2 border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-50 transition-all duration-200 font-medium;
    }
    .input-modern:disabled, .input-modern:read-only {
        @apply bg-slate-50 text-slate-600;
    }
    .step-badge {
        @apply w-8 h-8 rounded-lg flex items-center justify-center text-sm font-black shadow-lg;
    }
</style>

<div class="min-h-screen bg-gradient-to-br from-slate-50 via-blue-50 to-purple-50 py-12 px-4 sm:px-6 lg:px-8">
    <!-- Background Decorative Elements -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none opacity-30">
        <div class="absolute top-20 left-1/4 w-96 h-96 bg-blue-200 rounded-full mix-blend-multiply filter blur-3xl"></div>
        <div class="absolute bottom-20 right-1/4 w-96 h-96 bg-purple-200 rounded-full mix-blend-multiply filter blur-3xl"></div>
    </div>

    <div class="max-w-4xl mx-auto relative">
        <!-- Header Section -->
        <div class="text-center mb-8 animate-fade-in-up">
            <div class="flex justify-center mb-4">
                <div class="w-16 h-16 bg-gradient-to-br from-blue-600 to-purple-600 rounded-2xl flex items-center justify-center shadow-2xl">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                </div>
            </div>
            <h1 class="text-4xl md:text-5xl font-black text-slate-900 mb-3">
                <span class="gradient-text">School Registration</span>
            </h1>
            <p class="text-slate-600 text-lg font-medium">Get started in minutes with your custom payment portal</p>
        </div>

        <!-- Main Form Card -->
        <div class="glass-card rounded-2xl p-8 md:p-10 animate-fade-in-up" style="animation-delay: 0.1s;">
            <!-- Progress Steps -->
            <div class="mb-8 pb-6 border-b-2 border-slate-100">
                <div class="flex items-center justify-between max-w-2xl mx-auto">
                    <div class="flex flex-col items-center flex-1">
                        <div class="step-badge bg-gradient-to-br from-blue-500 to-blue-600 text-white">1</div>
                        <span class="text-xs font-bold text-slate-700 mt-2">School Info</span>
                    </div>
                    <div class="w-full h-1 bg-slate-200 -mt-8 flex-1 max-w-[100px]"></div>
                    <div class="flex flex-col items-center flex-1">
                        <div class="step-badge bg-gradient-to-br from-purple-500 to-purple-600 text-white">2</div>
                        <span class="text-xs font-bold text-slate-700 mt-2">Bank Details</span>
                    </div>
                    <div class="w-full h-1 bg-slate-200 -mt-8 flex-1 max-w-[100px]"></div>
                    <div class="flex flex-col items-center flex-1">
                        <div class="step-badge bg-gradient-to-br from-emerald-500 to-emerald-600 text-white">3</div>
                        <span class="text-xs font-bold text-slate-700 mt-2">Complete</span>
                    </div>
                </div>
            </div>

            @if ($errors->any())
                <div class="mb-6 rounded-xl border-2 border-red-200 bg-red-50 p-5 animate-slide-in-right">
                    <div class="flex items-start gap-3">
                        <div class="w-6 h-6 bg-red-500 rounded-lg flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <p class="font-bold text-red-800 mb-2">Please fix the following errors:</p>
                            <ul class="space-y-1 text-red-700 text-sm">
                                @foreach ($errors->all() as $error)
                                    <li class="flex items-start gap-2">
                                        <span class="text-red-500 mt-1">•</span>
                                        <span>{{ $error }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif

            <form id="registration_form" action="{{ route('registration.store') }}" method="POST" class="space-y-8">
                @csrf

                <!-- Section 1: School Information -->
                <div class="space-y-5">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">School Information</h2>
                            <p class="text-slate-600 text-sm">Basic details about your institution</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-bold text-slate-700 mb-2" for="name">
                                School Name *
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                </div>
                                <input id="name" name="name" type="text" value="{{ old('name') }}" required
                                       class="input-modern pl-11" placeholder="e.g., Springfield Academy" />
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-bold text-slate-700 mb-2" for="email">
                                School Email *
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <input id="email" name="email" type="email" value="{{ old('email') }}" required
                                       class="input-modern pl-11" placeholder="admin@springfieldacademy.edu" />
                            </div>
                            <p class="mt-1.5 text-xs text-slate-500 flex items-center gap-1">
                                <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Login credentials will be sent to this email
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2" for="admin_password">
                                Admin Password *
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                </div>
                                <input id="admin_password" name="admin_password" type="password" required
                                       class="input-modern pl-11" placeholder="••••••••" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2" for="admin_password_confirmation">
                                Confirm Password *
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                    </svg>
                                </div>
                                <input id="admin_password_confirmation" name="admin_password_confirmation" type="password" required
                                       class="input-modern pl-11" placeholder="••••••••" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 p-3 bg-blue-50 rounded-lg border border-blue-100">
                        <input id="toggle_admin_pw" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 w-4 h-4" />
                        <label for="toggle_admin_pw" class="text-sm font-medium text-slate-700 select-none cursor-pointer">
                            Show passwords
                        </label>
                    </div>
                </div>

                <!-- Divider -->
                <div class="border-t-2 border-slate-100"></div>

                <!-- Section 2: Bank Details -->
                <div class="space-y-5">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Bank Account Details</h2>
                            <p class="text-slate-600 text-sm">Where you'll receive payments</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2" for="bank">
                            School Bank *
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            </div>
                            <select id="bank" name="bank" required
                                    class="input-modern pl-11 appearance-none bg-white">
                                <option value="">Loading banks...</option>
                            </select>
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </div>
                        <input type="hidden" id="bank_code" name="bank_code" value="{{ old('bank_code') }}" />
                        @error('bank_code')
                            <div class="mt-1.5 text-sm text-red-600 flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2" for="account_number">
                                Account Number *
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                                    </svg>
                                </div>
                                <input id="account_number" name="account_number" type="text" value="{{ old('account_number') }}" required
                                       class="input-modern pl-11" placeholder="0123456789" maxlength="10" />
                            </div>
                            <p class="mt-1.5 text-xs text-slate-500">10-digit account number</p>
                        </div>

                        <div class="relative">
                            <label class="block text-sm font-bold text-slate-700 mb-2" for="account_name">
                                Account Name (Auto-verified)
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                </div>
                                <input id="account_name" name="account_name" type="text" value="{{ old('account_name') }}" readonly
                                       class="input-modern pl-11 pr-10" placeholder="Verifying..." />
                                <div id="account_name_status" class="absolute inset-y-0 right-0 pr-3 flex items-center"></div>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 bg-purple-50 rounded-xl border border-purple-100">
                        <div class="flex items-start gap-3">
                            <div class="w-5 h-5 bg-purple-500 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-purple-900 mb-1">Automatic Verification</p>
                                <p class="text-sm text-purple-700">We'll verify your account name automatically when you enter your account number. This ensures payments go to the correct account.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Divider -->
                <div class="border-t-2 border-slate-100"></div>

                <!-- Section 3: Additional Details -->
                <div class="space-y-5">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-10 h-10 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-slate-900">Additional Information</h2>
                            <p class="text-slate-600 text-sm">Optional details (can be added later)</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2" for="address">
                            School Address
                            <span class="text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                            </div>
                            <input id="address" name="address" type="text" value="{{ old('address') }}"
                                   class="input-modern pl-11" placeholder="123 Education Street, City, State" />
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="pt-6 border-t-2 border-slate-100">
                    <div class="flex flex-col sm:flex-row gap-3 justify-between items-center">
                        <a href="{{ route('home') }}" 
                           class="inline-flex items-center gap-2 px-5 py-3 rounded-lg border-2 border-slate-300 text-slate-700 hover:bg-slate-50 font-bold transition-all duration-200 w-full sm:w-auto justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            Back to Home
                        </a>

                        <button id="register_button" type="submit" 
                                class="group inline-flex items-center justify-center gap-2 px-8 py-3.5 rounded-lg bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white font-bold shadow-xl hover:shadow-2xl transform hover:-translate-y-0.5 transition-all duration-200 w-full sm:w-auto">
                            <svg class="w-5 h-5 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span id="register_button_text">Complete Registration</span>
                            <i id="register_spinner" class="fas fa-spinner fa-spin hidden"></i>
                        </button>

                        <a href="{{ route('admin.login') }}" 
                           class="inline-flex items-center gap-2 px-5 py-3 rounded-lg border-2 border-slate-300 text-slate-700 hover:bg-slate-50 font-bold transition-all duration-200 w-full sm:w-auto justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                            </svg>
                            Admin Login
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <div class="mt-6 glass-card rounded-2xl p-6 text-center animate-fade-in-up" style="animation-delay: 0.2s;">
            <div class="flex items-center justify-center gap-3">
                <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-slate-600 font-medium">Need help? Contact support@example.com</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bankSelect = document.getElementById('bank');
    const bankCodeInput = document.getElementById('bank_code');
    const accountNumberInput = document.getElementById('account_number');
    const accountNameInput = document.getElementById('account_name');
    const accountNameStatus = document.getElementById('account_name_status');
    const toggleAdminPw = document.getElementById('toggle_admin_pw');
    const adminPassword = document.getElementById('admin_password');
    const adminPasswordConfirm = document.getElementById('admin_password_confirmation');
    const registerButton = document.getElementById('register_button');
    const registerButtonText = document.getElementById('register_button_text');
    const registerSpinner = document.getElementById('register_spinner');

    let verifyController = null;

    async function loadBanks() {
        try {
            const response = await fetch('/api/banks?country=nigeria');
            const data = await response.json();

            if (data.ok && data.banks) {
                bankSelect.innerHTML = '<option value="">Select your bank</option>';
                data.banks.forEach(bank => {
                    const option = document.createElement('option');
                    option.value = bank.name;
                    option.textContent = bank.name;
                    option.dataset.code = bank.code;
                    bankSelect.appendChild(option);
                });
            } else {
                bankSelect.innerHTML = '<option value="">Failed to load banks</option>';
            }
        } catch (error) {
            console.error('Error loading banks:', error);
            bankSelect.innerHTML = '<option value="">Error loading banks</option>';
        }
    }

    bankSelect.addEventListener('change', function() {
        const selectedOption = bankSelect.options[bankSelect.selectedIndex];
        if (selectedOption && selectedOption.dataset.code) {
            bankCodeInput.value = selectedOption.dataset.code;
        } else {
            bankCodeInput.value = '';
        }
        verifyAccount();
    });

    accountNumberInput.addEventListener('input', function() {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
        if (this.value.length === 10) {
            verifyAccount();
        } else {
            accountNameInput.value = '';
            accountNameStatus.innerHTML = '';
        }
    });

    async function verifyAccount() {
        const accountNumber = accountNumberInput.value;
        const bankCode = bankCodeInput.value;

        if (accountNumber.length !== 10 || !bankCode) {
            return;
        }

        if (verifyController) {
            verifyController.abort();
        }

        verifyController = new AbortController();

        accountNameInput.value = '';
        accountNameStatus.innerHTML = `
            <svg class="w-5 h-5 text-blue-500 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
            </svg>
        `;

        try {
            const response = await fetch(`/api/resolve-account?account_number=${accountNumber}&bank_code=${bankCode}`, {
                signal: verifyController.signal
            });

            const data = await response.json();

            if (data.ok && data.account_name) {
                accountNameInput.value = data.account_name;
                accountNameStatus.innerHTML = `
                    <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                `;
            } else {
                accountNameStatus.innerHTML = `
                    <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                `;
                accountNameInput.placeholder = data.error || 'Verification failed';
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Error verifying account:', error);
                accountNameStatus.innerHTML = `
                    <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                `;
            }
        }
    }

    toggleAdminPw.addEventListener('change', function() {
        const type = this.checked ? 'text' : 'password';
        adminPassword.type = type;
        adminPasswordConfirm.type = type;
    });

    document.getElementById('registration_form').addEventListener('submit', function() {
        registerButton.disabled = true;
        registerButtonText.classList.add('hidden');
        registerSpinner.classList.remove('hidden');
    });

    loadBanks();
});
</script>

@endsection