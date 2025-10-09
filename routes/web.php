<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SubcategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SchoolAuthController;
use App\Http\Controllers\PaystackController;
use Illuminate\Support\Facades\Mail;
use App\Models\School;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureSchoolAdmin;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::resource('categories', CategoryController::class);
Route::resource('subcategories', SubcategoryController::class);
Route::resource('transactions', TransactionController::class);

// Payment routes - cleaned up and organized
Route::get('/payment', [PaymentController::class, 'index'])->name('payment.index');
Route::post('/payment/initialize', [PaymentController::class, 'initialize'])->name('payment.initialize');
Route::get('/payment/callback', [PaymentController::class, 'callback'])->name('payment.callback');
// Receipt routes
Route::get('/payment/receipt/{transaction}', [PaymentController::class, 'receipt'])->name('payment.receipt');
Route::get('/payment/receipt/{transaction}/download', [PaymentController::class, 'downloadReceipt'])->name('payment.receipt.download');

// Registration routes
Route::get('/registration/create', [RegistrationController::class, 'create'])->name('registration.create');
Route::post('/registration', [RegistrationController::class, 'store'])->name('registration.store');

// Paystack helper routes (server-side; uses secret key)
Route::get('/api/banks', [PaystackController::class, 'banks'])->name('api.banks');
Route::get('/api/resolve-account', [PaystackController::class, 'resolveAccount'])->name('api.resolve-account');

// Contact routes
Route::get('/contact', function () {
    return view('contact');
})->name('contact.show');

Route::post('/contact', function (\Illuminate\Http\Request $request) {
    $data = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'subject' => 'required|string|max:255',
        'message' => 'required|string|max:5000',
    ]);

    $to = config('mail.from.address');
    try {
        Mail::raw(
            "From: {$data['name']} <{$data['email']}>\n\n" . $data['message'],
            function ($m) use ($to, $data) {
                $m->to($to)->subject('[Contact] ' . $data['subject']);
                $m->replyTo($data['email'], $data['name']);
            }
        );
    } catch (\Throwable $e) {
        return back()->withInput()->with('error', 'Unable to send your message. Please try again later.');
    }

    return redirect()->route('contact.show')->with('success', 'Your message has been sent. We will get back to you shortly.');
})->name('contact.send');

// Admin auth routes (school-level)
Route::get('/admin/login', [SchoolAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [SchoolAuthController::class, 'login'])->name('admin.login.post');
Route::post('/admin/logout', [SchoolAuthController::class, 'logout'])->name('admin.logout');

// Tenant-aware public payment routes per school
Route::prefix('s/{school:slug}')->group(function () {
    Route::get('/payment', [PaymentController::class, 'indexSchool'])->name('school.payment.index');
    Route::post('/payment/initialize', [PaymentController::class, 'initializeSchool'])->name('school.payment.initialize');
    // callback remains global (Paystack redirects there)

    // Tenant-aware management pages (protected)
    Route::middleware(EnsureSchoolAdmin::class)->group(function () {
        Route::get('/categories', [CategoryController::class, 'indexSchool'])->name('school.categories.index');
        Route::post('/categories', [CategoryController::class, 'storeSchool'])->name('school.categories.store');

        Route::get('/subcategories', [SubcategoryController::class, 'indexSchool'])->name('school.subcategories.index');
        Route::get('/subcategories/create', [SubcategoryController::class, 'createSchool'])->name('school.subcategories.create');
        Route::post('/subcategories', [SubcategoryController::class, 'storeSchool'])->name('school.subcategories.store');

        Route::get('/transactions', [TransactionController::class, 'indexSchool'])->name('school.transactions.index');
    });
});

// Optional success & failed pages -> redirect to index with flash
Route::get('/payment/success', function () {
    return redirect()->route('payment.index')->with('success', 'Payment successful!');
})->name('payment.success');

Route::get('/payment/failed', function () {
    return redirect()->route('payment.index')->with('error', 'Payment failed!');
})->name('payment.failed');

// TEMP: SMTP test route (remove in production)
Route::get('/test-mail', function (Request $request) {
    $to = $request->query('to');
    if (!$to) {
        return response()->json(['error' => 'Provide ?to=recipient@example.com'], 400);
    }
    try {
        Mail::raw('Test email from School Fees Portal. If you received this, SMTP is working.', function ($message) use ($to) {
            $message->to($to)->subject('SMTP Test');
        });
        return response()->json(['ok' => true, 'sent_to' => $to]);
    } catch (\Throwable $e) {
        report($e);
        return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
});
