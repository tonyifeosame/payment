@extends('layouts.app')

@section('content')
<style>
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-20px); }
    }
    @keyframes pulse-glow {
        0%, 100% { box-shadow: 0 0 20px rgba(59, 130, 246, 0.4); }
        50% { box-shadow: 0 0 40px rgba(59, 130, 246, 0.6); }
    }
    .animate-fade-in-up {
        animation: fadeInUp 0.8s ease-out;
    }
    .animate-float {
        animation: float 6s ease-in-out infinite;
    }
    .gradient-text {
        background: linear-gradient(135deg, #1e40af 0%, #7c3aed 50%, #059669 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .glass-effect {
        backdrop-filter: blur(20px);
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 relative">
    <!-- Background Decorative Elements -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none -z-10">
        <div class="absolute top-20 left-10 w-72 h-72 bg-blue-200 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-float"></div>
        <div class="absolute top-40 right-10 w-72 h-72 bg-purple-200 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-float" style="animation-delay: 2s;"></div>
        <div class="absolute bottom-40 left-1/2 w-72 h-72 bg-emerald-200 rounded-full mix-blend-multiply filter blur-3xl opacity-30 animate-float" style="animation-delay: 4s;"></div>
    </div>

    <!-- Hero Section -->
    <section class="glass-effect rounded-3xl shadow-2xl p-8 md:p-16 text-center mb-8 relative overflow-hidden animate-fade-in-up border border-blue-100" style="background-image: url('/images/ChatGPT Image Oct 29, 2025, 08_12_44 PM.png'); background-size: cover;">
        <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-blue-600 via-purple-600 to-emerald-600"></div>
        
        <!-- Icon -->
        <div class="flex justify-center mb-6">
            <div class="relative">
                <div class="w-24 h-24 bg-gradient-to-br from-blue-600 via-purple-600 to-emerald-600 rounded-3xl flex items-center justify-center shadow-2xl transform hover:scale-105 transition-transform duration-300">
                    <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                </div>
                <div class="absolute -top-2 -right-2 w-6 h-6 bg-green-500 rounded-full border-4 border-white flex items-center justify-center">
                    <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                    </svg>
                </div>
            </div>
        </div>

        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-4">
            <span class="gradient-text">Simple School Payments</span>
        </h1>
        <p class="mt-4 text-slate-600 text-lg md:text-xl max-w-3xl mx-auto font-medium leading-relaxed">
            Collect fees, track transactions, and get daily payouts to your school account. Modern, secure, and effortless.
        </p>

        <!-- CTA Buttons -->
        <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-center items-center flex-wrap">
            <a href="{{ route('registration.create') }}" 
               class="group inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-500 hover:to-blue-600 text-white font-bold shadow-xl hover:shadow-2xl transform hover:-translate-y-1 transition-all duration-300 min-w-[220px]">
                <svg class="w-5 h-5 group-hover:rotate-12 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Register Your School
            </a>
            <a href="{{ route('admin.login') }}" 
               class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl border-2 border-slate-300 bg-white text-slate-800 hover:bg-slate-50 hover:border-slate-400 font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-300 min-w-[220px]">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                School Admin Login
            </a>
            <a href="{{ route('payment.index') }}" 
               class="group inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-gradient-to-r from-emerald-600 to-emerald-700 hover:from-emerald-500 hover:to-emerald-600 text-white font-bold shadow-xl hover:shadow-2xl transform hover:-translate-y-1 transition-all duration-300 min-w-[220px]">
                <svg class="w-5 h-5 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Make a Payment
            </a>
            <a href="{{ route('contact.show') }}" 
               class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl border-2 border-slate-300 bg-white text-slate-800 hover:bg-slate-50 hover:border-slate-400 font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-300 min-w-[220px]">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                Contact Us
            </a>
        </div>
    </section>

    <!-- Find Your School -->
    <section class="glass-effect rounded-3xl shadow-xl p-8 mb-8 animate-fade-in-up border border-purple-100" style="animation-delay: 0.1s;">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-pink-500 rounded-xl flex items-center justify-center shadow-lg">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Find Your School</h2>
                <p class="text-slate-600 text-sm">Enter your school name or slug to access the payment portal</p>
            </div>
        </div>

        <div class="flex flex-col md:flex-row md:items-end gap-4">
            <div class="flex-1">
                <label for="schoolSlug" class="block text-sm font-bold text-slate-700 mb-2 uppercase tracking-wide">School Name or Slug</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                    </div>
                    <input id="schoolSlug" type="text" placeholder="e.g. life-school or Life School" 
                           class="w-full pl-12 pr-4 py-4 rounded-xl border-2 border-slate-200 focus:border-purple-500 focus:ring-4 focus:ring-purple-100 transition-all duration-200 font-medium text-lg" />
                </div>
                <div class="mt-2 flex items-center gap-2 text-sm text-slate-500">
                    <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="font-medium">Your payment link looks like <code class="px-2 py-0.5 bg-purple-50 text-purple-700 rounded font-mono text-xs">/s/{school}/payment</code></span>
                </div>
            </div>
            <div>
                <button id="goSchool" 
                        class="group inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-gradient-to-r from-slate-800 to-slate-900 hover:from-slate-700 hover:to-slate-800 text-white font-bold shadow-xl hover:shadow-2xl transform hover:-translate-y-1 transition-all duration-300 whitespace-nowrap">
                    Go to School Payment
                    <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                    </svg>
                </button>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="mb-8 animate-fade-in-up" style="animation-delay: 0.2s;">
        <div class="text-center mb-8">
            <h2 class="text-3xl md:text-4xl font-black text-slate-900 mb-3">How It Works</h2>
            <p class="text-slate-600 text-lg">Three simple steps to transform your school's payment system</p>
        </div>

        <div class="relative rounded-3xl shadow-2xl overflow-hidden">
            <img src="{{ asset('images/ChatGPT Image Oct 27, 2025, 08_45_40 AM.png') }}" 
                 alt="A person pointing at a screen" 
                 class="w-full h-auto object-cover">

            <div class="absolute top-0 right-0 h-full w-full md:w-1/2 flex items-center p-8 md:p-12">
                <div class="flex flex-col gap-6">
                    <div class="group relative glass-effect rounded-2xl shadow-lg hover:shadow-2xl p-8 transition-all duration-300 hover:-translate-y-2 border border-blue-100">
                        <div class="absolute -top-4 left-8 w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg text-white font-black text-xl">1</div>
                        <div class="mt-4">
                            <div class="w-14 h-14 bg-blue-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-slate-900 mb-3">Register Your School</h3>
                            <p class="text-slate-600 leading-relaxed">Add bank details; account name is auto-verified. Create categories and fee types with ease.</p>
                        </div>
                    </div>

                    <div class="group relative glass-effect rounded-2xl shadow-lg hover:shadow-2xl p-8 transition-all duration-300 hover:-translate-y-2 border border-purple-100">
                        <div class="absolute -top-4 left-8 w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg text-white font-black text-xl">2</div>
                        <div class="mt-4">
                            <div class="w-14 h-14 bg-purple-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                                <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-slate-900 mb-3">Share Payment Link</h3>
                            <p class="text-slate-600 leading-relaxed">Parents/students pay securely via Paystack. Receipts are generated automatically for every transaction.</p>
                        </div>
                    </div>

                    <div class="group relative glass-effect rounded-2xl shadow-lg hover:shadow-2xl p-8 transition-all duration-300 hover:-translate-y-2 border border-emerald-100">
                        <div class="absolute -top-4 left-8 w-12 h-12 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg text-white font-black text-xl">3</div>
                        <div class="mt-4">
                            <div class="w-14 h-14 bg-emerald-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                                <svg class="w-8 h-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-slate-900 mb-3">Get Paid Daily</h3>
                            <p class="text-slate-600 leading-relaxed">Daily 9AM payouts transfer previous day's collections directly to your school account.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Highlights/Features -->
    <section class="mb-8 animate-fade-in-up" style="animation-delay: 0.3s;">
        <div class="text-center mb-8">
            <h2 class="text-3xl md:text-4xl font-black text-slate-900 mb-3">Why Schools Choose Us</h2>
            <p class="text-slate-600 text-lg">Built specifically for educational institutions</p>
        </div>

        <div class="relative rounded-3xl shadow-2xl overflow-hidden">
            <img src="{{ asset('images/ChatGPT Image Oct 27, 2025, 08_00_09 AM.png') }}" 
                 alt="A friendly student smiling" 
                 class="w-full h-auto object-cover">

            <div class="absolute top-0 left-0 h-full w-full md:w-1/2 flex items-center p-8 md:p-12">
                <div class="flex flex-col gap-6">
                    <div class="group glass-effect rounded-2xl shadow-lg hover:shadow-2xl p-8 transition-all duration-300 border border-slate-200 hover:border-blue-300">
                        <div class="flex items-center gap-4 mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-xl flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-slate-900">Secure Payments</h3>
                        </div>
                        <p class="text-slate-600 leading-relaxed">Powered by Paystack with SSL encryption. All references and receipts are securely stored and accessible.</p>
                    </div>

                    <div class="group glass-effect rounded-2xl shadow-lg hover:shadow-2xl p-8 transition-all duration-300 border border-slate-200 hover:border-purple-300">
                        <div class="flex items-center gap-4 mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-pink-500 rounded-xl flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-slate-900">Admin Dashboard</h3>
                        </div>
                        <p class="text-slate-600 leading-relaxed">Manage categories, fee types, and view all transactions. Everything is school-scoped for your privacy.</p>
                    </div>

                    <div class="group glass-effect rounded-2xl shadow-lg hover:shadow-2xl p-8 transition-all duration-300 border border-slate-200 hover:border-emerald-300">
                        <div class="flex items-center gap-4 mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-emerald-500 to-teal-500 rounded-xl flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-slate-900">Auto Bank Verify</h3>
                        </div>
                        <p class="text-slate-600 leading-relaxed">Banks fetched from Paystack; account name is resolved automatically for added security and convenience.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Support Section -->
    <section class="glass-effect rounded-3xl shadow-xl p-8 md:p-12 text-center animate-fade-in-up border border-blue-100" style="animation-delay: 0.4s;">
        <div class="flex justify-center mb-6">
            <div class="w-16 h-16 bg-gradient-to-br from-blue-600 to-purple-600 rounded-2xl flex items-center justify-center shadow-xl">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
            </div>
        </div>
        <h2 class="text-2xl md:text-3xl font-bold text-slate-900 mb-3">Need Help?</h2>
        <p class="text-slate-600 text-lg mb-8 max-w-2xl mx-auto">Check your email settings or contact our support team. We're here to help your school succeed.</p>
        
        <div class="flex flex-col sm:flex-row justify-center gap-4">
            <a href="{{ route('admin.login') }}" 
               class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl border-2 border-slate-300 bg-white text-slate-800 hover:bg-slate-50 hover:border-slate-400 font-bold shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all duration-300">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                </svg>
                Admin Login
            </a>
            <a href="{{ route('registration.create') }}" 
               class="inline-flex items-center justify-center gap-2 px-8 py-4 rounded-xl bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-500 hover:to-blue-600 text-white font-bold shadow-xl hover:shadow-2xl transform hover:-translate-y-1 transition-all duration-300">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Register School
            </a>
        </div>
    </section>
</div>

<script>
(function(){
    const input = document.getElementById('schoolSlug');
    const btn = document.getElementById('goSchool');
    function go(){
        const raw = (input.value || '').trim();
        if (!raw) return;
        // Create a URL-safe slug (basic)
        const slug = raw.toLowerCase().replace(/[^a-z0-9\-\s]/g, '').replace(/\s+/g, '-');
        window.location.href = '/s/' + encodeURIComponent(slug) + '/payment';
    }
    btn?.addEventListener('click', go);
    input?.addEventListener('keydown', function(e){ if (e.key === 'Enter') go(); });
})();
</script>
@endsection