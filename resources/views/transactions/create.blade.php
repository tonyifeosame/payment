
<x-app-layout>

    <div class="max-w-lg mx-auto py-6">
        <h1 class="text-2xl font-bold mb-4">Add Transaction</h1>

        <form action="{{ route('transactions.store') }}" method="POST" class="space-y-4">
            @csrf

            <div>
                <label class="block mb-1">Amount</label>
                <input type="number" name="amount" step="0.01" 
                       class="w-full border rounded px-3 py-2" required>
            </div>

            <div>
                <label class="block mb-1">Category</label>
                <select name="category_id" class="w-full border rounded px-3 py-2" required>
                    <option value="">Select category</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block mb-1">Subcategory</label>
                <select name="subcategory_id" class="w-full border rounded px-3 py-2">
                    <option value="">Optional</option>
                    @foreach($subcategories as $subcategory)
                        <option value="{{ $subcategory->id }}">{{ $subcategory->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block mb-1">Description</label>
                <textarea name="description" class="w-full border rounded px-3 py-2"></textarea>
            </div>

            <button type="submit" 
                    class="px-4 py-2 bg-green-600 text-white rounded">Save</button>
        </form>
    </div>
</x-app-layout>
