<footer class="border-t border-brand-fog bg-white">
    <div class="container-x py-12 lg:py-16">
        <div class="grid gap-10 md:grid-cols-12 md:gap-8">
            <div class="md:col-span-5">
                @include('marketing.partials.logo')
                <p class="mt-4 max-w-xs text-sm text-brand-slate">School fees, collected without the stress.</p>
            </div>

            <nav class="grid grid-cols-2 gap-8 md:col-span-4" aria-label="Footer">
                <div>
                    <h2 class="font-sans text-xs font-semibold uppercase tracking-[0.12em] text-brand-obsidian">Product</h2>
                    <ul class="mt-4 space-y-3 text-sm">
                        <li><a href="#features" class="text-brand-slate hover:text-brand-obsidian">Features</a></li>
                        <li><a href="#how-it-works" class="text-brand-slate hover:text-brand-obsidian">How it works</a></li>
                    </ul>
                </div>
                <div>
                    <h2 class="font-sans text-xs font-semibold uppercase tracking-[0.12em] text-brand-obsidian">Company</h2>
                    <ul class="mt-4 space-y-3 text-sm">
                        <li><a href="{{ route('contact.show') }}" class="text-brand-slate hover:text-brand-obsidian">Contact</a></li>
                    </ul>
                </div>
            </nav>

            <div class="flex flex-col gap-3 sm:flex-row md:col-span-3 md:flex-col md:items-end lg:flex-row lg:items-start lg:justify-end">
                <a href="{{ route('admin.login') }}" class="btn-outline btn-sm">Sign in</a>
                <a href="{{ route('registration.create') }}" class="btn-obsidian btn-sm">Get started</a>
            </div>
        </div>

        <div class="mt-12 flex flex-col gap-2 border-t border-brand-fog pt-6 text-xs text-brand-slate sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ date('Y') }} @include('marketing.partials.brand-name'). All rights reserved.</p>
            <p>Online school fee collection for schools in Nigeria.</p>
        </div>
    </div>
</footer>
