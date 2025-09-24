<div class="min-h-screen bg-gray-100">
    <!-- Navbar -->
    <nav class="bg-blue-600 p-4 text-white flex space-x-6">
        <a href="{{ url('/categories') }}" class="hover:underline">Categories</a>
        <a href="{{ url('/subcategories') }}" class="hover:underline">Subcategories</a>
        <a href="{{ url('/transactions') }}" class="hover:underline">Transactions</a>
    </nav>

    <!-- Page Content -->
    <main class="p-6">
        {{ $slot }}
    </main>
</div>
