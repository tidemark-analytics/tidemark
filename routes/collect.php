<?php

use App\Http\Controllers\EventController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

// Event collection under a per-site address (/e/<code>), the counterpart of the per-site script file name. Same handler as
// /api/event, which stays: it must never start a session or set a cookie. The code only varies the address; the site is still
// named by the event itself, so a copied snippet keeps working.
Route::withoutMiddleware(['throttle:api', StartSession::class, EncryptCookies::class, AddQueuedCookiesToResponse::class])->middleware('cors.collect')->group(function () {
    Route::match(['post', 'options'], '/e/{code}', [EventController::class, 'store'])->where('code', '[a-f0-9]{10}')->name('event.alias');
});
