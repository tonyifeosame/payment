<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel App') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100 text-gray-800">

    <!-- Navbar -->
    <nav class="bg-blue-600 p-4 text-white">
        <div class="max-w-6xl mx-auto flex flex-wrap items-center gap-4">
            @if(Session::get('password_protected_auth'))
                <a href="{{ route('logout') }}" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">Logout</a>
            @endif
        </div>
    </nav>

    <main class="p-4 sm:p-6 max-w-6xl mx-auto">
        @yield('content')
    </main>

</body>
</html>
