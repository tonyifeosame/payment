<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ config('app.name', 'Laravel App') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">

    <!-- Navbar -->
    <nav class="bg-blue-600 p-4 text-white flex space-x-6">
        <a href="{{ url('/categories') }}" class="hover:underline">Categories</a>
        <a href="{{ url('/subcategories') }}" class="hover:underline">Subcategories</a>
        <a href="{{ url('/transactions') }}" class="hover:underline">Transactions</a>
    </nav>

    <main class="p-6">
        @yield('content')
    </main>

</body>
</html>
