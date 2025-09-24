<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SubcategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\PaymentController;

Route::get('/', function () {
    return redirect()->route('categories.index');
});

Route::resource('categories', CategoryController::class);
Route::resource('subcategories', SubcategoryController::class);
Route::resource('transactions', TransactionController::class);

// Payment routes - cleaned up and organized
Route::get('/payment', [PaymentController::class, 'index'])->name('payment.index');
Route::post('/payment/initialize', [PaymentController::class, 'initialize'])->name('payment.initialize');
Route::get('/payment/callback', [PaymentController::class, 'callback'])->name('payment.callback');

// Optional success & failed pages -> redirect to index with flash
Route::get('/payment/success', function () {
    return redirect()->route('payment.index')->with('success', 'Payment successful!');
})->name('payment.success');

Route::get('/payment/failed', function () {
    return redirect()->route('payment.index')->with('error', 'Payment failed!');
})->name('payment.failed');
