<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/posts', [PostController::class, 'index'])->name('posts.index');
Route::get('/posts/{id}', [PostController::class, 'show'])->name('posts.show');
Route::get('/posts/search', [PostController::class, 'search']);
Route::post('/posts', [PostController::class, 'store']);
Route::get('/posts/popular', [PostController::class, 'popular']);

Route::post('/avatar', [AvatarController::class, 'store']);

Route::get('/reports/orders.csv', [ReportController::class, 'export']);
Route::get('/reports/monthly', [ReportController::class, 'monthlyTotals']);

// FLAW: no throttle middleware on an authentication endpoint.
Route::post('/login', fn () => abort(501))->name('login');

// FLAW: the admin group has auth but no admin gate or policy behind it.
Route::prefix('admin')->middleware('auth')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
});
