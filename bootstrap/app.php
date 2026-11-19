<?php

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyPixelforgeSignature;
use App\Pixelforge\PixelforgeException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Outside the `web` group: no session, no CSRF. Signature is the auth.
        then: function () {
            Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
                ->middleware(VerifyPixelforgeSignature::class)
                ->name('webhooks.pixelforge');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Logging out from a stale tab (session expired, or already logged out
        // elsewhere) fails CSRF with a 419. The user wants to be logged out
        // anyway, so finish the job and land on the welcome page.
        // (Laravel has already turned the TokenMismatchException into a 419 HttpException here.)
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $request->routeIs('logout')) {
                return null;
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        });

        // Pixelforge unreachable while serving a user request (e.g. /v1/pricing
        // during validation): explain instead of a 500. Nothing was created.
        $exceptions->render(function (PixelforgeException $e, Request $request) {
            if ($request->routeIs('webhooks.*')) {
                return null;
            }

            $message = 'Pixelforge is temporarily unavailable, so we could not price this request. Nothing was submitted or charged. Please try again in a moment.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 503)
                : back()->withInput()->with('error', $message);
        });
    })->create();
