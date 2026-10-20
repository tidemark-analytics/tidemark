<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SettingsController as S;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\StatsApiController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\UpdateController;
use App\Http\Controllers\EventController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

// ---- public event collection (cross-origin, no session) — Plausible-compatible -------------------------------
// The endpoint must never start a session or set a cookie: that is what makes the tracker cookie-free.
Route::withoutMiddleware(['throttle:api', StartSession::class, EncryptCookies::class, AddQueuedCookiesToResponse::class])->middleware('cors.collect')->group(function () {
    Route::match(['post', 'options'], '/event', [EventController::class, 'store'])->name('event');
});

// ---- auth & public config --------------------------------------------------------------------------------------
Route::get('/config', [AuthController::class, 'config']);
Route::middleware('api.auth:optional')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Readable by members, by anyone when the dashboard is public, or via a shared link.
    Route::middleware('site:view')->prefix('/sites/{site}')->group(function () {
        Route::get('/', [SiteController::class, 'show']);
        Route::get('/stats/top', [StatsController::class, 'top']);
        Route::get('/stats/graph', [StatsController::class, 'graph']);
        Route::get('/stats/breakdown/{dim}', [StatsController::class, 'breakdown']);
        Route::get('/stats/goals', [StatsController::class, 'goals']);
        Route::get('/stats/props', [StatsController::class, 'props']);
        Route::get('/stats/props/{key}', [StatsController::class, 'propValues'])->where('key', '.+');
        Route::get('/stats/current-visitors', [StatsController::class, 'currentVisitors']);
        Route::get('/stats/export', [StatsController::class, 'export']);
    });
});

// ---- authenticated ---------------------------------------------------------------------------------------------
Route::middleware('api.auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me', [AuthController::class, 'update']);
    Route::post('/me/password', [AuthController::class, 'password']);
    Route::post('/me/two-factor', [AuthController::class, 'twoFactorSetup']);
    Route::post('/me/two-factor/confirm', [AuthController::class, 'twoFactorConfirm']);
    Route::delete('/me/two-factor', [AuthController::class, 'twoFactorDisable']);
    Route::get('/me/keys', [AuthController::class, 'keys']);
    Route::post('/me/keys', [AuthController::class, 'createKey']);
    Route::delete('/me/keys/{id}', [AuthController::class, 'deleteKey']);

    Route::get('/sites', [SiteController::class, 'index']);
    Route::post('/sites', [SiteController::class, 'store']);

    Route::middleware('site:manage')->prefix('/sites/{site}')->group(function () {
        Route::patch('/', [SiteController::class, 'update']);
        Route::post('/reset', [SiteController::class, 'reset']);
        Route::get('/goals', [S::class, 'goals']);
        Route::post('/goals', [S::class, 'createGoal']);
        Route::delete('/goals/{goal}', [S::class, 'deleteGoal']);
        Route::get('/shared-links', [S::class, 'sharedLinks']);
        Route::post('/shared-links', [S::class, 'createSharedLink']);
        Route::patch('/shared-links/{link}', [S::class, 'updateSharedLink']);
        Route::delete('/shared-links/{link}', [S::class, 'deleteSharedLink']);
        Route::get('/shields', [S::class, 'shields']);
        Route::post('/shields', [S::class, 'createShield']);
        Route::delete('/shields/{rule}', [S::class, 'deleteShield']);
        Route::get('/people', [S::class, 'people']);
        Route::post('/people', [S::class, 'addPerson']);
        Route::patch('/people/{user}', [S::class, 'updatePerson']);
        Route::delete('/people/{user}', [S::class, 'removePerson']);
    });
    Route::middleware('site:owner')->delete('/sites/{site}', [SiteController::class, 'destroy']);

    Route::get('/admin/users', [AdminController::class, 'index']);
    Route::post('/admin/users', [AdminController::class, 'store']);
    Route::post('/admin/users/{user}', [AdminController::class, 'update']);
    Route::delete('/admin/users/{user}', [AdminController::class, 'destroy']);

    Route::get('/admin/update', [UpdateController::class, 'show']);
    Route::get('/admin/update/check', [UpdateController::class, 'check']);
    Route::post('/admin/update/download', [UpdateController::class, 'download']);
    Route::post('/admin/update/chunk', [UpdateController::class, 'chunk']);
    Route::post('/admin/update/apply', [UpdateController::class, 'apply']);
    Route::delete('/admin/update', [UpdateController::class, 'discard']);

    // Stats API v1 (Plausible-compatible subset)
    Route::prefix('/v1/stats')->group(function () {
        Route::get('/realtime/visitors', [StatsApiController::class, 'realtime']);
        Route::get('/aggregate', [StatsApiController::class, 'aggregate']);
        Route::get('/timeseries', [StatsApiController::class, 'timeseries']);
        Route::get('/breakdown', [StatsApiController::class, 'breakdown']);
    });
});
