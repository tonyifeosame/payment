<?php

use App\Http\Controllers\AcademicSessionController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\PaystackController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SchoolAuthController;
use App\Http\Controllers\SchoolSettingsController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SubcategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Middleware\EnsureSchoolAdmin;
use App\Models\School;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::redirect('/login', '/admin/login');

// Admin landing for the payment page. The un-scoped management routes that used to
// live here (categories/subcategories/transactions resources) were removed: they were
// guarded only by "is some school admin logged in" and therefore exposed and mutated
// every school's data. All management now happens under the /s/{school} prefix below,
// where the school is bound, ownership-checked and scoped.
Route::middleware(EnsureSchoolAdmin::class)->group(function () {
    Route::get('/payment', [PaymentController::class, 'index'])->name('payment.index');
});

Route::get('/payment/callback', [PaymentController::class, 'callback'])->name('payment.callback');

// The Paystack webhook lives in routes/webhooks.php, registered without the web
// middleware group so it starts no session and issues no cookies.

// Receipt routes. Authorization happens in the controller (signed URL, paying session,
// or the owning school admin) because these are reached from emails as well as the UI.
Route::get('/payment/receipt/{transaction}', [PaymentController::class, 'receipt'])->name('payment.receipt');
Route::get('/payment/receipt/{transaction}/download', [PaymentController::class, 'downloadReceipt'])->name('payment.receipt.download');

// Registration routes
Route::get('/registration/create', [RegistrationController::class, 'create'])->name('registration.create');
Route::post('/registration', [RegistrationController::class, 'store'])
    ->middleware('throttle:10,60')
    ->name('registration.store');

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
            "From: {$data['name']} <{$data['email']}>\n\n".$data['message'],
            function ($m) use ($to, $data) {
                $m->to($to)->subject('[Contact] '.$data['subject']);
                $m->replyTo($data['email'], $data['name']);
            }
        );
    } catch (\Throwable $e) {
        report($e);

        return back()->withInput()->with('error', 'Unable to send your message. Please try again later.');
    }

    return redirect()->route('contact.show')->with('success', 'Your message has been sent. We will get back to you shortly.');
})
    // Unauthenticated and it sends mail, so it is rate limited like registration.
    // The recipient is fixed to config('mail.from.address') and cannot be chosen by
    // the sender, so the risk is flooding our own inbox and burning our sending
    // reputation rather than relaying to third parties. Five an hour per IP is far
    // above real use and well below what makes a useful flood.
    ->middleware('throttle:5,60')
    ->name('contact.send');

// Admin auth routes (school-level)
Route::get('/admin/login', [SchoolAuthController::class, 'showLogin'])->name('admin.login');
// Throttled per IP like the other password-accepting endpoints: five attempts per hour.
Route::post('/admin/login', [SchoolAuthController::class, 'login'])
    ->middleware('throttle:5,60')
    ->name('admin.login.post');
Route::post('/admin/logout', [SchoolAuthController::class, 'logout'])->name('admin.logout');
Route::get('admin/forgot-password', [SchoolAuthController::class, 'showLinkRequestForm'])->name('admin.password.request');
Route::post('admin/forgot-password', [SchoolAuthController::class, 'sendResetLinkEmail'])->name('admin.password.email');
Route::get('admin/reset-password/{token}', [SchoolAuthController::class, 'showResetForm'])->name('admin.password.reset');
Route::post('admin/reset-password', [SchoolAuthController::class, 'reset'])->name('admin.password.update');

// Tenant-aware public payment routes per school
Route::prefix('s/{school:slug}')->group(function () {
    Route::get('/payment', [PaymentController::class, 'indexSchool'])->name('school.payment.index');
    Route::post('/payment/initialize', [PaymentController::class, 'initializeSchool'])->name('school.payment.initialize');
    // Public student autocomplete for the payment form. Throttled per IP because it
    // is unauthenticated and lists (a capped number of) this school's students by
    // name. The browser debounces, so a parent typing a name costs a handful of hits.
    Route::get('/payment/student-search', [PaymentController::class, 'studentSearch'])
        ->middleware('throttle:60,1')
        ->name('school.payment.student-search');
    // The school's logo: public, because it appears on the parent-facing page.
    Route::get('/logo', [SchoolSettingsController::class, 'logo'])->name('school.logo');
    // callback remains global (Paystack redirects there)

    // Tenant-aware management pages (protected).
    // scopeBindings() makes {category}/{subcategory} resolve through the bound school's
    // relationship, so a record belonging to another school 404s during route binding —
    // before any controller code runs. Controllers additionally assert ownership.
    Route::middleware(EnsureSchoolAdmin::class)->scopeBindings()->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('school.dashboard');

        // Roster. {student} is scope-bound through School::students().
        Route::get('/students', [StudentController::class, 'index'])->name('school.students.index');
        Route::get('/students/create', [StudentController::class, 'create'])->name('school.students.create');
        Route::post('/students', [StudentController::class, 'store'])->name('school.students.store');
        Route::get('/students/{student}', [StudentController::class, 'show'])->name('school.students.show');
        Route::get('/students/{student}/edit', [StudentController::class, 'edit'])->name('school.students.edit');
        Route::put('/students/{student}', [StudentController::class, 'update'])->name('school.students.update');

        // Academic sessions and terms. {academicTerm} is scope-bound through School::academicTerms().
        Route::get('/sessions', [AcademicSessionController::class, 'index'])->name('school.sessions.index');
        Route::post('/sessions', [AcademicSessionController::class, 'store'])->name('school.sessions.store');
        Route::post('/terms/{academicTerm}/current', [AcademicSessionController::class, 'setCurrent'])->name('school.terms.current');

        // Money out: read-only ledger.
        Route::get('/payouts', [PayoutController::class, 'indexSchool'])->name('school.payouts.index');

        // Profile, branding and payout account.
        Route::get('/settings', [SchoolSettingsController::class, 'edit'])->name('school.settings.edit');
        Route::put('/settings', [SchoolSettingsController::class, 'update'])->name('school.settings.update');
        Route::put('/settings/bank', [SchoolSettingsController::class, 'updateBank'])
            ->middleware('throttle:5,60') // password guesses against the bank form
            ->name('school.settings.bank');

        // Sharing the public payment page.
        Route::get('/share', [ShareController::class, 'index'])->name('school.share.index');
        Route::get('/share/qr.svg', [ShareController::class, 'qr'])->name('school.share.qr');

        Route::get('/categories', [CategoryController::class, 'indexSchool'])->name('school.categories.index');
        Route::post('/categories', [CategoryController::class, 'storeSchool'])->name('school.categories.store');

        Route::get('/subcategories', [SubcategoryController::class, 'indexSchool'])->name('school.subcategories.index');
        Route::get('/subcategories/create', [SubcategoryController::class, 'createSchool'])->name('school.subcategories.create');
        Route::post('/subcategories', [SubcategoryController::class, 'storeSchool'])->name('school.subcategories.store');

        Route::get('/transactions', [TransactionController::class, 'indexSchool'])->name('school.transactions.index');
        Route::get('/transactions/export', [TransactionController::class, 'exportSchool'])->name('school.transactions.export');

        Route::get('/categories/{category}/edit', [CategoryController::class, 'editSchool'])->name('school.categories.edit');
        Route::put('/categories/{category}', [CategoryController::class, 'updateSchool'])->name('school.categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroySchool'])->name('school.categories.destroy');

        Route::get('/subcategories/{subcategory}/edit', [SubcategoryController::class, 'editSchool'])->name('school.subcategories.edit');
        Route::put('/subcategories/{subcategory}', [SubcategoryController::class, 'updateSchool'])->name('school.subcategories.update');
        Route::delete('/subcategories/{subcategory}', [SubcategoryController::class, 'destroySchool'])->name('school.subcategories.destroy');
    });
});

// Optional success & failed pages -> redirect to index with flash
Route::get('/payment/success', function () {
    return redirect()->route('payment.index')->with('success', 'Payment successful!');
})->name('payment.success');

Route::get('/payment/failed', function () {
    return redirect()->route('payment.index')->with('error', 'Payment failed!');
})->name('payment.failed');

// The temporary /test-mail route was removed. It accepted an arbitrary ?to=
// address with no authentication, throttling or ownership check, so anyone who
// found it could send mail from this application's domain to any recipient —
// an open relay for our sending reputation. SMTP is verified locally with
// Mailpit (see README) and in production by an actual receipt delivery; neither
// needs a public endpoint. Do not reintroduce an unauthenticated mail sender.
