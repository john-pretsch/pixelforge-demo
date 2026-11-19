<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ReferenceImageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The generations page is the app's home.
    Route::redirect('dashboard', '/generations')->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generations')->name('generations.store');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])
        ->middleware('throttle:generations')->name('generations.retry');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:estimates')->name('generations.estimate');

    // Local reference image → public URL usable as a Pixelforge reference image.
    Route::post('reference-images', [ReferenceImageController::class, 'store'])
        ->middleware('throttle:uploads')->name('reference-images.store');

    // Fake purchase (no payment): adds a fixed pack of credits.
    Route::post('credits', [CreditController::class, 'store'])
        ->middleware('throttle:generations')->name('credits.purchase');

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('report', [ReportController::class, 'index'])->name('report');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
