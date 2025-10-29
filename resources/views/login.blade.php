@extends('layouts.app')

@section('content')
<style>
    @keyframes fadeInScale {
        from { 
            opacity: 0; 
            transform: scale(0.95) translateY(20px); 
        }
        to { 
            opacity: 1; 
            transform: scale(1) translateY(0); 
        }
    }
    @keyframes floatSlow {
        0%, 100% { transform: translate(0, 0) rotate(0deg); }
        25% { transform: translate(10px, -10px) rotate(1deg); }
        50% { transform: translate(-5px, -20px) rotate(-1deg); }
        75% { transform: translate(-10px, -10px) rotate(0.5deg); }
    }
    @keyframes gradient-shift {
        0%, 100% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
    }
    @keyframes glow {
        0%, 100% { box-shadow: 0 0 20px rgba(59, 130, 246, 0.2), 0 0 40px rgba(139, 92, 246, 0.1); }
        50% { box-shadow: 0 0 30px rgba(59, 130, 246, 0.3), 0 0 60px rgba(139, 92, 246, 0.2); }
    }
    .animate-fade-in-scale {
        animation: fadeInScale 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .animate-float-slow {
        animation: floatSlow 20s ease-in-out infinite;
    }
    .gradient-animated {
        background: linear-gradient(270deg, #3b82f6, #8b5cf6, #ec4899, #3b82f6);
        background-size: 400% 400%;
        animation: gradient-shift 15s ease infinite;
    }
    .glass-premium {
        backdrop-filter: blur(40px) saturate(180%);
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(255, 255, 255, 0.5);
        box-shadow: 
            0 8px 32px rgba(31, 38, 135, 0.15),
            0 2px 8px rgba(0, 0, 0, 0.1),
            inset 0 1px 0 rgba(255, 255, 255, 0.6);
    }
    .input-premium {
        @apply w-full px-4 py-3.5 rounded-xl border-2 border-slate-200 bg-white/80 backdrop-blur-sm;
        @apply focus:border-blue-500 focus:ring-4 focus:ring-blue-100 focus:bg-white;
        @apply transition-all duration-300 font-medium text-slate-900 placeholder-slate-400;
    }
    .input-premium:focus {
        transform: translateY(-1px);
        box-shadow: 0 4px 20px rgba(59, 130, 246, 0.15);
    }
    .btn-primary-premium {
        @apply w-full px-6 py-4 text-white font-bold rounded-xl shadow-2xl;
        background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #ec4899 100%);
        background-size: 200% 200%;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .btn-primary-premium:hover {
        background-position: 100% 0;
        transform: translateY(-3px);
        box-shadow: 0 20px 40px rgba(59, 130, 246, 0.4);
    }
    .btn-secondary-premium {
        @apply w-full px-6 py-3.5 rounded-xl font-bold transition-all duration-300;
        @apply border-2 border-slate-200 bg-white/60 backdrop-blur-sm text-slate-700;
        @apply hover:bg-white hover:border-blue-300 hover:text-blue-700 hover:shadow-lg;
    }
    .glow-effect {
        animation: glow 3s ease-in-out infinite;
    }
</style>

<div class="min-h-screen relative overflow-hidden flex items-center justify-center px-4 py-12">
    <!-- Dynamic Background -->
    <div class="absolute inset-0 bg-gradient-to-br from-blue-50 via-purple-50 to-pink-50"></div>
    
    <!-- Animated Background Orbs -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 right-1/4 w-96 h-96 bg-gradient-to-br from-blue-400/30 to-cyan-300/30 rounded-full blur-3xl animate-float-slow"></div>
        <div class="absolute bottom-0 left-1/4 w-96 h-96 bg-gradient-to-br from-purple-400/30 to-pink-300/30 rounded-full blur-3xl animate-float-slow" style="animation-delay: -5s;"></div>
        <div class="absolute top-1/2 left-1/2 w-96 h-96 bg-gradient-to-br from-pink-400/20 to-orange-300/20 rounded-full blur-3xl animate-float-slow" style="animation-delay: -10s;"></div>
    </div>

    <div class="w-full max-w-md relative z-10">
        <!-- Logo Section with Glow -->
        <div class="text-center mb-8 animate-fade-in-scale">
            <div class="inline-flex justify-center mb-6">
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-br from-blue-500 to-purple-600 rounded-3xl blur-xl opacity-60 glow-effect"></div>
                    <div class="relative w-20 h-20 gradient-animated rounded-3xl flex items-center justify-center shadow-2xl transform hover:scale-110 hover:rotate-6 transition-all duration-500">
                        <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    </div>
                </div>
            </div>
            <h1 class="text-5xl font-black mb-3 bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 bg-clip-text text-transparent">
                Welcome Back
            </h1>
            <p class="text-slate-600 text-lg font-semibold">Access your school admin dashboard</p>
        </div>

        <!-- Premium Login Card -->
        <div class="glass-premium rounded-3xl p-8 md:p-10 animate-fade-in-scale shadow-2xl" style="animation-delay: 0.1s;">
            <!-- Security Notice -->
            <div class="mb-8 p-4 rounded-2xl bg-gradient-to-r from-blue-50 to-purple-50 border-2 border-blue-100/50">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-black text-slate-900 mb-0.5">Bank-Level Security</p>
                        <p class="text-xs text-slate-600 font-medium">256-bit encryption protects your credentials</p>
                    </div>
                </div>
            </div>

            <form method="POST" action="/admin/login" class="space-y-6">
                @csrf
                
                <!-- Username Field -->
                <div class="space-y-2">
                    <label for="name" class="block text-sm font-black text-slate-800 uppercase tracking-wide">
                        School Name
                    </label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none transition-all duration-300 group-focus-within:scale-110">
                            <svg class="w-5 h-5 text-slate-400 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <input id="name" name="name" type="text" required
                               class="input-premium pl-12"
                               placeholder="Enter your school name"
                               value="{{ old('name') }}" />
                    </div>
                    @error('name')
                        <div class="flex items-center gap-2 text-red-600 bg-red-50 px-3 py-2 rounded-lg">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="text-sm font-bold">{{ $message }}</p>
                        </div>
                    @enderror
                </div>

                <!-- Password Field -->
                <div class="space-y-2">
                    <label for="password" class="block text-sm font-black text-slate-800 uppercase tracking-wide">
                        Password
                    </label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none transition-all duration-300 group-focus-within:scale-110">
                            <svg class="w-5 h-5 text-slate-400 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <input id="password" name="password" type="password" required 
                               class="input-premium pl-12 pr-12" 
                               placeholder="Enter your password" />
                        <button type="button" 
                                onclick="togglePassword()" 
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-blue-600 transition-all duration-300 hover:scale-110">
                            <svg id="eye-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                        </button>
                    </div>
                    @error('password')
                        <div class="flex items-center gap-2 text-red-600 bg-red-50 px-3 py-2 rounded-lg">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <p class="text-sm font-bold">{{ $message }}</p>
                        </div>
                    @enderror
                </div>

                <!-- Remember & Forgot -->
                <div class="flex items-center justify-between pt-2">
                    <label class="flex items-center gap-2.5 cursor-pointer group">
                        <input type="checkbox" name="remember" 
                               class="w-5 h-5 rounded-lg border-2 border-slate-300 text-blue-600 focus:ring-4 focus:ring-blue-100 transition-all cursor-pointer">
                        <span class="text-sm font-bold text-slate-700 group-hover:text-blue-600 transition-colors">Remember me</span>
                    </label>
                    <a href="#" class="text-sm font-black text-transparent bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text hover:from-blue-700 hover:to-purple-700 transition-all">
                        Forgot Password?
                    </a>
                </div>

                <!-- Premium Login Button -->
                <div class="pt-4">
                    <button type="submit" 
                            class="btn-primary-premium group flex items-center justify-center gap-3">
                        <svg class="w-6 h-6 transition-transform duration-300 group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                        <span class="text-lg">Sign In to Dashboard</span>
                        <svg class="w-6 h-6 transition-transform duration-300 group-hover:translate-x-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                        </svg>
                    </button>
                </div>
            </form>

            <!-- Elegant Divider -->
            <div class="my-8 flex items-center">
                <div class="flex-1 h-px bg-gradient-to-r from-transparent via-slate-300 to-transparent"></div>
                <span class="px-4 text-sm font-black text-slate-400 uppercase tracking-wider">Or Continue</span>
                <div class="flex-1 h-px bg-gradient-to-r from-transparent via-slate-300 to-transparent"></div>
            </div>

            <!-- Action Links -->
            <div class="space-y-3">
                <a href="{{ route('registration.create') }}" 
                   class="btn-secondary-premium group flex items-center justify-center gap-2.5">
                    <svg class="w-5 h-5 transition-transform duration-300 group-hover:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                    </svg>
                    <span>Register Your School</span>
                </a>
                
                <a href="{{ route('home') }}" 
                   class="btn-secondary-premium group flex items-center justify-center gap-2.5">
                    <svg class="w-5 h-5 transition-transform duration-300 group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span>Back to Home</span>
                </a>
            </div>
        </div>

        <!-- Footer Links -->
        <div class="mt-8 text-center space-y-4 animate-fade-in-scale" style="animation-delay: 0.3s;">
            <p class="text-sm text-slate-600 font-medium">
                Need assistance? 
                <a href="{{ route('contact.show') }}" class="font-black text-transparent bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text hover:from-blue-700 hover:to-purple-700 transition-all">
                    Contact Our Support Team
                </a>
            </p>
            
            <!-- Trust Indicators -->
            <div class="flex items-center justify-center gap-8 pt-2">
                <div class="flex items-center gap-2 text-slate-500">
                    <div class="w-8 h-8 bg-gradient-to-br from-green-400 to-emerald-500 rounded-lg flex items-center justify-center shadow-lg">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                    </div>
                    <span class="text-xs font-black">SSL Secured</span>
                </div>
                <div class="flex items-center gap-2 text-slate-500">
                    <div class="w-8 h-8 bg-gradient-to-br from-blue-400 to-blue-600 rounded-lg flex items-center justify-center shadow-lg">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    </div>
                    <span class="text-xs font-black">256-bit Encrypted</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eye-icon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>';
    } else {
        passwordInput.type = 'password';
        eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>';
    }
}
</script>
@endsection