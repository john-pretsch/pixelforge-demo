<?php

use App\Http\Controllers\GenerationController;
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
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:estimates')->name('generations.estimate');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
