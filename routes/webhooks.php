<?php

use App\Http\Controllers\PaystackWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Server-to-server callbacks from payment providers. These are registered in
| bootstrap/app.php with NO middleware group, deliberately:
|
|   - there is no browser, so starting a session and issuing session/XSRF
|     cookies is pure overhead (and, with the database session driver, a row
|     written on every delivery);
|   - there is no CSRF token to present, so CSRF validation cannot apply;
|   - authentication is the HMAC signature over the raw request body, which
|     the controller verifies before the payload is parsed.
|
*/

Route::post('/paystack/webhook', PaystackWebhookController::class)->name('paystack.webhook');
