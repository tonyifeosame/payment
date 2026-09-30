{{-- Slim footer for focused public pages; pairs with partials/slim-header. --}}
<footer class="border-t border-brand-fog bg-white">
    <div class="container-x flex flex-col gap-2 py-6 text-xs text-brand-slate sm:flex-row sm:items-center sm:justify-between">
        <p>&copy; {{ date('Y') }} @include('marketing.partials.brand-name'). All rights reserved.</p>
        <p class="flex gap-4">
            <a href="{{ route('home') }}" class="hover:text-brand-obsidian">Homepage</a>
            <a href="{{ route('contact.show') }}" class="hover:text-brand-obsidian">Contact</a>
            <a href="{{ route('privacy.show') }}" class="hover:text-brand-obsidian">Privacy</a>
        </p>
    </div>
</footer>
