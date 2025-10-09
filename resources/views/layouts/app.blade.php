<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel App') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-800">

    <!-- Navbar -->
    <nav class="bg-blue-600 p-4 text-white">
        <div class="max-w-6xl mx-auto flex flex-wrap items-center gap-4">
            <!-- Navbar intentionally left blank. Category/Subcategory/Transaction pages render their own navbars. -->
        </div>
    </nav>

    <main class="p-4 sm:p-6 max-w-6xl mx-auto">
        @yield('content')
    </main>

</body>
</html>
