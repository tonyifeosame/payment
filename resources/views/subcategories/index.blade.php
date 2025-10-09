<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subcategories</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800">

    <!-- Navbar -->
    <nav class="bg-blue-600 p-4 text-white shadow">
        <div class="max-w-6xl mx-auto flex items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-4">
                @isset($school)
                    <a href="{{ route('school.categories.index', ['school' => $school->slug]) }}" class="hover:underline">Categories</a>
                    <a href="{{ route('school.subcategories.index', ['school' => $school->slug]) }}" class="hover:underline font-semibold">Subcategories</a>
                    <a href="{{ route('school.transactions.index', ['school' => $school->slug]) }}" class="hover:underline">Transactions</a>
                @else
                    <a href="{{ route('categories.index') }}" class="hover:underline">Categories</a>
                    <a href="{{ route('subcategories.index') }}" class="hover:underline font-semibold">Subcategories</a>
                    <a href="{{ route('transactions.index') }}" class="hover:underline">Transactions</a>
                @endisset
            </div>
            <div>
                <a href="{{ route('contact.show') }}" class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/20 text-white px-3 py-1.5 rounded-md">Contact</a>
            </div>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto p-4 sm:p-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between mb-6">
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-slate-900">Subcategories</h1>
                @isset($school)
                    <p class="text-sm text-slate-600">School: <span class="font-medium">{{ $school->name }}</span></p>
                @endisset
            </div>
            <div class="mt-2 sm:mt-0">
                <a href="@isset($school){{ route('school.subcategories.create', ['school' => $school->slug]) }}@else{{ route('subcategories.create') }}@endisset"
                   class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-md shadow-sm hover:bg-blue-700">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M12 4.5a.75.75 0 01.75.75v6h6a.75.75 0 010 1.5h-6v6a.75.75 0 01-1.5 0v-6h-6a.75.75 0 010-1.5h6v-6A.75.75 0 0112 4.5z"/></svg>
                    Add Subcategory
                </a>
            </div>
        </div>

        <!-- Flash message -->
        @if(session('success'))
            <div class="mb-4 rounded-md border border-green-200 bg-green-50 p-3 text-green-700">
                {{ session('success') }}
            </div>
        @endif

        <!-- Subcategories Table -->
        <div class="overflow-x-auto bg-white rounded-xl shadow-sm border border-slate-200">
            <table class="min-w-full">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="p-3 text-left text-xs font-semibold uppercase tracking-wide">#</th>
                        <th class="p-3 text-left text-xs font-semibold uppercase tracking-wide">Category</th>
                        <th class="p-3 text-left text-xs font-semibold uppercase tracking-wide">Name</th>
                        <th class="p-3 text-right text-xs font-semibold uppercase tracking-wide">Price</th>
                        <th class="p-3 text-right text-xs font-semibold uppercase tracking-wide">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($subcategories as $sub)
                        <tr class="hover:bg-slate-50">
                            <td class="p-3 align-top text-slate-600">{{ $sub->id }}</td>
                            <td class="p-3 align-top">
                                <span class="inline-flex items-center rounded-full bg-slate-100 text-slate-700 px-2.5 py-0.5 text-xs font-medium">{{ $sub->category->name ?? 'N/A' }}</span>
                            </td>
                            <td class="p-3 align-top font-medium text-slate-900">{{ $sub->name }}</td>
                            <td class="p-3 align-top text-right font-medium text-slate-900">₦{{ number_format($sub->price, 2) }}</td>
                            <td class="p-3 align-top">
                                <div class="flex justify-end gap-2">
                                    <form action="{{ route('subcategories.destroy', $sub->id) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this subcategory?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center gap-1 bg-red-600 text-white px-3 py-1.5 rounded-md hover:bg-red-700">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-6 text-center">
                                <div class="text-slate-500">No subcategories found.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>
