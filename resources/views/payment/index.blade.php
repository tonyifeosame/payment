<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Fees Payment</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-200">

    <header class="border-b bg-white/70 backdrop-blur sticky top-0 z-10">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-slate-800">School Fees Portal</h1>
            <nav class="text-sm text-slate-500">Payment</nav>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8">
        <div class="mb-6">
            <h2 class="text-2xl md:text-3xl font-bold text-slate-800">Make a Payment</h2>
            <p class="text-slate-600 mt-1">Provide your details and select a fee to proceed.</p>
        </div>

        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="mb-4 rounded-md border border-green-200 bg-green-50 p-3 text-green-700">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-red-700">
                {{ session('error') }}
            </div>
        @endif

        {{-- Validation Errors --}}
        @if ($errors->any())
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-3">
                <p class="font-medium text-red-800 mb-1">There were some problems with your input:</p>
                <ul class="list-disc list-inside text-red-700 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-start">
            <div class="md:col-span-2">
                <form id="paymentForm" action="{{ route('payment.initialize') }}" method="POST" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 space-y-5">
                    @csrf

                    {{-- Personal Info --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="name" class="block text-sm font-medium text-slate-700">Full Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" autocomplete="name"
                                   class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" required>
                            @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="email"
                                   class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" required>
                            @error('email') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="admission_number" class="block text-sm font-medium text-slate-700">Admission Number</label>
                        <input type="text" id="admission_number" name="admission_number" value="{{ old('admission_number') }}"
                               class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" required>
                        @error('admission_number') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                    </div>

                    {{-- Fee Selection (Cascading Dropdowns) --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="category" class="block text-sm font-medium text-slate-700">Category</label>
                            <select name="category_id" id="category" class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" required>
                                <option value="">-- Select Category --</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>
                                        {{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                            <span id="catError" class="text-red-600 text-sm"></span>
                            @error('category_id') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="subcategory" class="block text-sm font-medium text-slate-700">Fee Type</label>
                            <select name="subcategory_id" id="subcategory" class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" required disabled>
                                <option value="">-- Select Fee Type --</option>
                            </select>
                            <p id="unitPrice" class="text-slate-600 text-sm mt-1">Unit Price: ₦0</p>
                            <span id="subError" class="text-red-600 text-sm"></span>
                            @error('subcategory_id') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="quantity" class="block text-sm font-medium text-slate-700">Quantity</label>
                            <input type="number" name="quantity" id="quantity" value="{{ old('quantity', 1) }}" min="1"
                                   class="mt-1 w-full rounded-md border-slate-300 focus:border-blue-500 focus:ring-blue-500" required>
                            <span id="qtyError" class="text-red-600 text-sm"></span>
                            @error('quantity') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    {{-- Total --}}
                    <div>
                        <label for="total" class="block text-sm font-medium text-slate-700">Total Amount (₦)</label>
                        <input type="text" id="total" name="total" class="mt-1 w-full rounded-md border-slate-300 bg-slate-50" readonly>
                    </div>

                    {{-- Hidden fields --}}
                    <input type="hidden" name="category_name" id="category_name">
                    <input type="hidden" name="subcategory_name" id="subcategory_name">
                    <input type="hidden" name="client_total" id="client_total">

                    <div class="pt-2">
                        <button id="submitBtn" type="submit" class="w-full inline-flex items-center justify-center gap-2 bg-blue-600 text-white py-2.5 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60 disabled:cursor-not-allowed">
                            <svg id="spinner" class="hidden h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            <span id="submitText">Proceed to Pay</span>
                        </button>
                    </div>
                </form>
            </div>

            {{-- Order Summary --}}
            <aside class="md:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 sticky top-24">
                    <h3 class="text-lg font-semibold text-slate-800">Order Summary</h3>
                    <div class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-600">Category</span>
                            <span id="summaryCategory" class="font-medium text-slate-800">—</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-600">Fee</span>
                            <span id="summarySub" class="font-medium text-slate-800">—</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-600">Unit Price</span>
                            <span id="summaryPrice" class="font-medium text-slate-800">₦0</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-600">Quantity</span>
                            <span id="summaryQty" class="font-medium text-slate-800">1</span>
                        </div>
                        <hr class="my-2">
                        <div class="flex justify-between text-base">
                            <span class="font-semibold text-slate-700">Total</span>
                            <span id="summaryTotal" class="font-bold text-slate-900">₦0</span>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <!-- Debug Panel (non-destructive) -->
        <details class="mt-6 bg-white border border-slate-200 rounded-lg p-4 text-sm text-slate-700">
            <summary class="cursor-pointer font-medium">Debug Info</summary>
            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p><strong>Categories Count:</strong> <span id="dbgCategoriesCount">0</span></p>
                    <p><strong>Selected Category ID:</strong> <span id="dbgCategoryId">—</span></p>
                    <p><strong>Selected Category Name:</strong> <span id="dbgCategoryName">—</span></p>
                    <p><strong>Subcategories Count:</strong> <span id="dbgSubCount">0</span></p>
                </div>
                <div>
                    <p><strong>Selected Subcategory ID:</strong> <span id="dbgSubId">—</span></p>
                    <p><strong>Selected Subcategory Name:</strong> <span id="dbgSubName">—</span></p>
                    <p><strong>Unit Price:</strong> <span id="dbgUnitPrice">₦0</span></p>
                    <p><strong>Qty:</strong> <span id="dbgQty">1</span></p>
                    <p><strong>Total:</strong> <span id="dbgTotal">₦0</span></p>
                </div>
            </div>
            <div class="mt-3">
                <p><strong>Errors:</strong></p>
                <ul class="list-disc list-inside">
                    <li>Category: <span id="dbgCatErr">—</span></li>
                    <li>Subcategory: <span id="dbgSubErr">—</span></li>
                    <li>Quantity: <span id="dbgQtyErr">—</span></li>
                </ul>
            </div>
        </details>
    </main>

    <script>
   // Cache DOM elements
const catSelect = document.getElementById('category');
const subSelect = document.getElementById('subcategory');
const qtyInput = document.getElementById('quantity');
const totalField = document.getElementById('total');
const catNameInput = document.getElementById('category_name');
const subNameInput = document.getElementById('subcategory_name');
const sCategory = document.getElementById('summaryCategory');
const sSub = document.getElementById('summarySub');
const sPrice = document.getElementById('summaryPrice');
const sQty = document.getElementById('summaryQty');
const sTotal = document.getElementById('summaryTotal');
const form = document.getElementById('paymentForm');
const submitBtn = document.getElementById('submitBtn');
const spinner = document.getElementById('spinner');
    const submitText = document.getElementById('submitText');
const catError = document.getElementById('catError');
const subError = document.getElementById('subError');
const qtyError = document.getElementById('qtyError');
const unitPriceP = document.getElementById('unitPrice');
const clientTotalInput = document.getElementById('client_total');

// Old values from server (if any)
const oldCategoryId = {!! json_encode(old('category_id')) !!};
const oldSubcategoryId = {!! json_encode(old('subcategory_id')) !!};

    // Use pre-sanitized structure from controller for reliability
    const categories = {!! json_encode($categoriesForJs) !!} || [];

// Populate subcategories when category changes and recalc
catSelect.addEventListener('change', function () {
    try {
        populateSubcategories();
        updateTotal();
    } catch (e) {
        console.error('Error on category change:', e);
    }
});
subSelect.addEventListener('change', function () {
    try {
        updateTotal();
    } catch (e) {
        console.error('Error on subcategory change:', e);
    }
});

    document.addEventListener('input', function (event) {
        if (event.target === qtyInput) {
            updateTotal();
        }
    });

// Optional: allow native submit (no JS interception)

// Function to update total
function updateTotal() {
    const selectedCatOption = catSelect.options[catSelect.selectedIndex];
    const selectedOption = subSelect.options[subSelect.selectedIndex];
    const price = Number(selectedOption?.getAttribute('data-price')) || 0;
    const qty = Math.max(1, Number(qtyInput.value) || 1);

    // Calculate
    const total = price * qty;
    const catName = selectedCatOption ? selectedCatOption.textContent : '';
    const subName = selectedOption?.getAttribute('data-subname') || (selectedOption ? selectedOption.textContent.trim() : '');

    // Update hidden inputs
    catNameInput.value = catName;
    subNameInput.value = subName;

    // Update summary UI
    sCategory.textContent = catName || '—';
    sSub.textContent = subName || '—';
    sPrice.textContent = toCurrency(price);
    sQty.textContent = String(qty);
    sTotal.textContent = toCurrency(total);

    totalField.value = total ? total.toLocaleString() : '';
    unitPriceP.textContent = 'Unit Price: ' + toCurrency(price);
    clientTotalInput.value = String(total);
    // Basic inline validation messages
    catError.textContent = !catSelect.value ? 'Please select a category' : '';
    subError.textContent = !subSelect.value ? 'Please select a fee type' : '';
    qtyError.textContent = (Number(qtyInput.value) || 0) < 1 ? 'Quantity must be at least 1' : '';
    updateDebug();
}

// Helpers
function toCurrency(n) {
    return '₦' + (Number(n) || 0).toLocaleString();
}

function populateSubcategories() {
    console.log('Populating subcategories. categories:', categories, 'selectedCat:', catSelect.value);
    // Clear current options
    while (subSelect.firstChild) subSelect.removeChild(subSelect.firstChild);
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '-- Select Fee Type --';
    subSelect.appendChild(placeholder);

    const catId = Number(catSelect.value || 0);
    if (!catId) {
        subSelect.disabled = true;
        unitPriceP.textContent = 'Unit Price: ₦0';
        return;
    }

    // categories is a PHP-encoded array with nested subcategories
    const cat = (categories || []).find(c => Number(c.id) === catId);
    const subs = (cat && cat.subcategories) ? cat.subcategories : [];
    if (subs.length === 0) {
        subSelect.disabled = true;
        unitPriceP.textContent = 'Unit Price: ₦0';
        console.warn('No subcategories for category id', catId);
        return;
    }

    subs.forEach(sub => {
        const opt = document.createElement('option');
        opt.value = sub.id;
        opt.textContent = `${sub.name} (₦${Number(sub.price || 0).toLocaleString()})`;
        opt.setAttribute('data-price', Number(sub.price || 0));
        opt.setAttribute('data-subname', sub.name);
        subSelect.appendChild(opt);
    });
    subSelect.disabled = false;
    // Auto-select old subcategory if provided, otherwise first
    if (oldSubcategoryId && subs.some(s => String(s.id) === String(oldSubcategoryId))) {
        subSelect.value = String(oldSubcategoryId);
    } else if (subs.length > 0) {
        subSelect.value = String(subs[0].id);
    }
    // After repopulating, recalc totals
    updateTotal();
}

// Initialize on load: use old category if available, else first
try {
    if (oldCategoryId && (categories || []).some(c => String(c.id) === String(oldCategoryId))) {
        catSelect.value = String(oldCategoryId);
    } else if ((categories || []).length > 0) {
        catSelect.value = String(categories[0].id);
    }
    populateSubcategories();
    updateTotal();
} catch (e) {
    console.error('Initialization error:', e);
}

// Debug panel updater
function updateDebug() {
    const dbgCategoriesCount = document.getElementById('dbgCategoriesCount');
    const dbgCategoryId = document.getElementById('dbgCategoryId');
    const dbgCategoryName = document.getElementById('dbgCategoryName');
    const dbgSubCount = document.getElementById('dbgSubCount');
    const dbgSubId = document.getElementById('dbgSubId');
    const dbgSubName = document.getElementById('dbgSubName');
    const dbgUnitPrice = document.getElementById('dbgUnitPrice');
    const dbgQty = document.getElementById('dbgQty');
    const dbgTotal = document.getElementById('dbgTotal');
    const dbgCatErr = document.getElementById('dbgCatErr');
    const dbgSubErr = document.getElementById('dbgSubErr');
    const dbgQtyErr = document.getElementById('dbgQtyErr');

    const selectedCatOption = catSelect.options[catSelect.selectedIndex];
    const selectedSubOption = subSelect.options[subSelect.selectedIndex];
    const catId = catSelect.value;
    const catName = selectedCatOption ? selectedCatOption.textContent : '';
    const price = Number(selectedSubOption?.getAttribute('data-price')) || 0;
    const qty = Math.max(1, Number(qtyInput.value) || 1);
    const total = price * qty;
    const cat = (categories || []).find(c => String(c.id) === String(catId));
    const subs = (cat && cat.subcategories) ? cat.subcategories : [];

    dbgCategoriesCount.textContent = String((categories || []).length);
    dbgCategoryId.textContent = catId || '—';
    dbgCategoryName.textContent = catName || '—';
    dbgSubCount.textContent = String(subs.length);
    dbgSubId.textContent = subSelect.value || '—';
    dbgSubName.textContent = selectedSubOption?.getAttribute('data-subname') || (selectedSubOption ? selectedSubOption.textContent.trim() : '—');
    dbgUnitPrice.textContent = toCurrency(price);
    dbgQty.textContent = String(qty);
    dbgTotal.textContent = toCurrency(total);
    dbgCatErr.textContent = catError.textContent || '—';
    dbgSubErr.textContent = subError.textContent || '—';
    dbgQtyErr.textContent = qtyError.textContent || '—';
}
    </script>

</body>
</html>
