<?php
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SubcategoryController;

Route::get('/categories', [CategoryController::class, 'index']);
Route::post('/categories', [CategoryController::class, 'store']);

Route::get('/subcategories', [SubcategoryController::class, 'index']);
Route::post('/subcategories', [SubcategoryController::class, 'store']);
