<?php

use App\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Browser installer (shared hosting)
Route::get('/install', [InstallController::class, 'show']);
Route::post('/install', [InstallController::class, 'store']);

// Tracking script. Plausible-style variants are selected by file name: script.outbound-links.file-downloads.js
Route::get('/js/{file}', function (string $file) {
    return response()->file(public_path('js/script.js'), [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'public, max-age=3600',
        'Access-Control-Allow-Origin' => '*',
    ]);
})->where('file', 'script(\.[a-z-]+)*\.js')->name('tracker');

// Single-page dashboard shell. A site's dashboard lives at /{domain}, like Plausible.
Route::middleware('installed')->group(function () {
    Route::get('/', fn () => redirect(Auth::check() ? '/sites' : '/login'));
    Route::view('/login', 'app');
    Route::view('/register', 'app');
    Route::view('/sites/{any?}', 'app')->where('any', '.*');
    Route::view('/settings/{any?}', 'app')->where('any', '.*');
    Route::view('/share/{any}', 'app')->where('any', '.*');
    Route::view('/{domain}/{any?}', 'app')->where('domain', '(?!(api|js|assets|install|up|storage|build)$)[^/]+')->where('any', '.*');
});
