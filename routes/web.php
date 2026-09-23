<?php

use App\Http\Controllers\AcademicSessionController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ClassLevelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\PaystackController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SchoolAuthController;
use App\Http\Controllers\SchoolSettingsController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentPromotionController;
use App\Http\Controllers\SubcategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Middleware\EnsureSchoolAdmin;
use App\Http\Middleware\RedirectLegacyAdminUrls;
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
// Every throttle below names its own bucket (third parameter). Without a prefix the
// throttle middleware keys guests on IP alone, so all throttled routes shared ONE
// counter per visitor: five student-search lookups on the public payment page were
// enough to lock that IP out of the admin login for an hour.
Route::post('/registration', [RegistrationController::class, 'store'])
    ->middleware('throttle:10,60,registration')
    ->name('registration.store');

// Paystack helper routes (server-side; uses secret key). Both are public and
// every request that reaches the controller is an outbound call on the same key
// that initialises payments and pays schools out, so both are throttled with
// their own named bucket (limits in App\Support\BankLookupLimiter, registered in
// bootstrap/app.php). The throttle runs before the controller, so a throttled
// lookup never calls Paystack; the 429 is JSON in the {ok:false,error} shape the
// registration and settings forms already display.
Route::get('/api/banks', [PaystackController::class, 'banks'])
    ->middleware('throttle:bank-list') // 20/min and 60/hour per client IP
    ->name('api.banks');
Route::get('/api/resolve-account', [PaystackController::class, 'resolveAccount'])
    ->middleware('throttle:bank-resolve') // 10/min and 40/hour per IP, plus 60/hour per signed-in school
    ->name('api.resolve-account');

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
    ->middleware('throttle:5,60,contact')
    ->name('contact.send');

// Admin auth routes (school-level)
Route::get('/admin/login', [SchoolAuthController::class, 'showLogin'])->name('admin.login');
// Throttled per IP like the other password-accepting endpoints: five attempts per hour
// (throttle:attempts,decayMinutes). Only the POST is limited; the form itself is not.
Route::post('/admin/login', [SchoolAuthController::class, 'login'])
    ->middleware('throttle:5,60,admin-login')
    ->name('admin.login.post');
Route::post('/admin/logout', [SchoolAuthController::class, 'logout'])->name('admin.logout');

// Installable admin app (Chrome "FEYRA Admin"). The manifest is linked ONLY from
// layouts/admin, so public pages are never installable. Scope is /admin/ and the
// start URL is /admin/ (same route as /admin) — the canonical admin entry point:
// the signed-in school's dashboard, otherwise login. Registered before /admin/{school:slug}/... so no slug
// can shadow it. There is no service worker: nothing is cached, offline is the
// browser's own error.
Route::get('/admin/manifest.webmanifest', [SchoolAuthController::class, 'manifest'])->name('admin.manifest');
Route::get('/admin', [SchoolAuthController::class, 'app'])->name('admin.home');
// Legacy compatibility only (the previous manifest's start URL); not referenced by
// the current manifest. Same action as /admin.
Route::get('/s/_app', [SchoolAuthController::class, 'app'])->name('admin.app');
// Password reset. Both POSTs are unauthenticated: the request side sends mail to
// an address the caller names, and the reset side accepts a token. Throttled per
// IP like every other credential-accepting or mail-sending endpoint here, each in
// its own named bucket. Only the POSTs are limited; the forms themselves are not,
// so a locked-out admin still sees the page and its message.
Route::get('admin/forgot-password', [SchoolAuthController::class, 'showLinkRequestForm'])->name('admin.password.request');
Route::post('admin/forgot-password', [SchoolAuthController::class, 'sendResetLinkEmail'])
    ->middleware('throttle:5,60,password-reset-request') // mail flooding + token rotation against a known school
    ->name('admin.password.email');
Route::get('admin/reset-password/{token}', [SchoolAuthController::class, 'showResetForm'])->name('admin.password.reset');
Route::post('admin/reset-password', [SchoolAuthController::class, 'reset'])
    ->middleware('throttle:10,60,password-reset') // a little higher: one token, several fumbled confirmations
    ->name('admin.password.update');

// ---------------------------------------------------------------------------
// Authenticated school-admin routes (URL migration, stage 2).
//
// Defined ONCE and registered twice: canonically at /admin/{school}/... with the
// existing `school.*` names — so every route() call, redirect and form in the app
// now produces canonical URLs without touching a controller or view — and, for
// bookmarks and links already in the wild, at the legacy /s/{school}/... prefix
// with the same middleware and bindings under `legacy.school.*` names. Legacy
// pages therefore render and behave exactly as before, with their links and forms
// already pointing at the canonical namespace. Nothing is duplicated: both
// registrations run the same closure over the same controllers.
// ---------------------------------------------------------------------------
$schoolAdminRoutes = function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('school.dashboard');

    // Roster. {student} is scope-bound through School::students().
    Route::get('/students', [StudentController::class, 'index'])->name('school.students.index');
    Route::get('/students/create', [StudentController::class, 'create'])->name('school.students.create');
    Route::post('/students', [StudentController::class, 'store'])->name('school.students.store');

    // The school's class ladder. {classLevel} is scope-bound through School::classLevels().
    // Literal /students/classes and /students/promotion are registered before
    // /students/{student}, and {student} is numeric-only, so they never collide.
    Route::get('/students/classes', [ClassLevelController::class, 'index'])->name('school.students.classes.index');
    Route::post('/students/classes', [ClassLevelController::class, 'store'])->name('school.students.classes.store');
    Route::post('/students/classes/assign', [ClassLevelController::class, 'assignLegacy'])->name('school.students.classes.assign');
    Route::put('/students/classes/{classLevel}', [ClassLevelController::class, 'update'])->name('school.students.classes.update');
    Route::post('/students/classes/{classLevel}/move', [ClassLevelController::class, 'move'])->name('school.students.classes.move');
    Route::delete('/students/classes/{classLevel}', [ClassLevelController::class, 'destroy'])->name('school.students.classes.destroy');

    // Bulk promotion: choose → review → apply. Every step re-validates server-side.
    Route::get('/students/promotion', [StudentPromotionController::class, 'index'])->name('school.students.promotion.index');
    Route::post('/students/promotion/review', [StudentPromotionController::class, 'review'])->name('school.students.promotion.review');
    Route::post('/students/promotion', [StudentPromotionController::class, 'store'])->name('school.students.promotion.store');
    Route::get('/students/{student}', [StudentController::class, 'show'])->whereNumber('student')->name('school.students.show');
    Route::get('/students/{student}/edit', [StudentController::class, 'edit'])->whereNumber('student')->name('school.students.edit');
    Route::put('/students/{student}', [StudentController::class, 'update'])->whereNumber('student')->name('school.students.update');

    // Academic sessions and terms. {academicTerm} is scope-bound through School::academicTerms().
    Route::get('/sessions', [AcademicSessionController::class, 'index'])->name('school.sessions.index');
    Route::post('/sessions', [AcademicSessionController::class, 'store'])->name('school.sessions.store');
    Route::post('/terms/{academicTerm}/current', [AcademicSessionController::class, 'setCurrent'])->name('school.terms.current');

    // Money out: read-only ledger. {payout} is scope-bound through School::payouts().
    Route::get('/payouts', [PayoutController::class, 'indexSchool'])->name('school.payouts.index');
    Route::get('/payouts/{payout}', [PayoutController::class, 'showSchool'])->whereNumber('payout')->name('school.payouts.show');

    // Profile, branding and payout account.
    Route::get('/settings', [SchoolSettingsController::class, 'edit'])->name('school.settings.edit');
    Route::put('/settings', [SchoolSettingsController::class, 'update'])->name('school.settings.update');
    Route::put('/settings/bank', [SchoolSettingsController::class, 'updateBank'])
        ->middleware('throttle:5,60,bank-change') // password guesses against the bank form
        ->name('school.settings.bank');
    Route::put('/settings/password', [SchoolSettingsController::class, 'updatePassword'])
        ->middleware('throttle:5,60,password-change') // password guesses against the change form
        ->name('school.settings.password');

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
    // Read-only detail. {transaction} is scope-bound through School::transactions(), so
    // another school's id 404s before the controller runs; whereNumber keeps the
    // literal /transactions/export above from ever being read as an id.
    Route::get('/transactions/{transaction}', [TransactionController::class, 'showSchool'])
        ->whereNumber('transaction')
        ->name('school.transactions.show');

    Route::get('/categories/{category}/edit', [CategoryController::class, 'editSchool'])->name('school.categories.edit');
    Route::put('/categories/{category}', [CategoryController::class, 'updateSchool'])->name('school.categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroySchool'])->name('school.categories.destroy');

    Route::get('/subcategories/{subcategory}/edit', [SubcategoryController::class, 'editSchool'])->name('school.subcategories.edit');
    Route::put('/subcategories/{subcategory}', [SubcategoryController::class, 'updateSchool'])->name('school.subcategories.update');
    Route::delete('/subcategories/{subcategory}', [SubcategoryController::class, 'destroySchool'])->name('school.subcategories.destroy');
};

// Canonical admin namespace.
Route::prefix('admin/{school:slug}')->middleware(EnsureSchoolAdmin::class)->scopeBindings()->group($schoolAdminRoutes);

// Canonical PUBLIC payment namespace (URL migration, stage 1). Aliases of the
// /s/{school}/payment routes below: same controller methods, same throttle bucket,
// same validation and tenant scoping — only the URL and route name differ. New
// payment links (share page, QR, emails, redirects) are generated from these;
// the /s/ forms stay registered and unchanged because printed QR codes, WhatsApp
// messages and bookmarks already point at them. Keeping the public pages out of
// /s/ is what lets the admin app scope become admin-only in a later stage.
Route::prefix('pay/{school:slug}')->group(function () {
    Route::get('/', [PaymentController::class, 'indexSchool'])->name('public.payment');
    // payment-initialize (bootstrap/app.php): 10/min and 60/hour per client IP,
    // one bucket for this and the legacy URL below. It runs before the controller,
    // so a throttled submit creates no pending transaction and never calls Paystack.
    Route::post('/initialize', [PaymentController::class, 'initializeSchool'])
        ->middleware('throttle:payment-initialize')
        ->name('public.payment.initialize');
    // Verified student lookup (L8): name + admission number, POST so neither is in a URL.
    Route::post('/student-search', [PaymentController::class, 'studentSearch'])
        ->middleware('throttle:60,1,student-search')
        ->name('public.payment.student-search');
});

// Tenant-aware public payment routes per school (legacy URLs, kept as-is)
Route::prefix('s/{school:slug}')->group(function () use ($schoolAdminRoutes) {
    Route::get('/payment', [PaymentController::class, 'indexSchool'])->name('school.payment.index');
    Route::post('/payment/initialize', [PaymentController::class, 'initializeSchool'])
        ->middleware('throttle:payment-initialize') // same bucket as /pay/{school}/initialize
        ->name('school.payment.initialize');
    // Public student lookup for the payment form (L8): returns one student only when
    // the typed full name and complete admission number both match. Throttled per
    // IP because it is unauthenticated; one lookup per "Find student" press.
    Route::post('/payment/student-search', [PaymentController::class, 'studentSearch'])
        ->middleware('throttle:60,1,student-search')
        ->name('school.payment.student-search');
    // The school's logo: public, because it appears on the parent-facing page.
    Route::get('/logo', [SchoolSettingsController::class, 'logo'])->name('school.logo');
    // callback remains global (Paystack redirects there)

    // Legacy admin URLs (see $schoolAdminRoutes above): same middleware and scoped
    // bindings, `legacy.` name prefix so canonical names stay canonical. Since the
    // URL migration's stage 3 a permitted GET is 301-redirected to /admin/{school}/…
    // by RedirectLegacyAdminUrls; write methods still run their unchanged actions.
    Route::middleware([EnsureSchoolAdmin::class, RedirectLegacyAdminUrls::class])->scopeBindings()->name('legacy.')->group($schoolAdminRoutes);
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
