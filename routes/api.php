<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AnnotationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\FaviconController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\PlausibleImportController;
use App\Http\Controllers\Api\ReportSubscriptionController;
use App\Http\Controllers\Api\RollupController;
use App\Http\Controllers\Api\SegmentController;
use App\Http\Controllers\Api\SettingsController as S;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\StatsApiController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TeamController;
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

// ---- the API description, public --------------------------------------------------------------------------------
Route::get('/v1/openapi.json', [OpenApiController::class, 'show']);

// ---- auth & public config --------------------------------------------------------------------------------------
Route::get('/config', [AuthController::class, 'config']);
Route::middleware('api.auth:optional')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::get('/invites/{token}', [InviteController::class, 'show'])->middleware('throttle:30,1');
    Route::post('/invites/{token}/accept', [InviteController::class, 'accept'])->middleware('throttle:10,1');
    Route::get('/auth/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware('signed')->name('verification.verify');

    // Readable by members, by anyone when the dashboard is public, or via a shared link.
    Route::middleware('site:view')->prefix('/sites/{site}')->group(function () {
        Route::get('/', [SiteController::class, 'show']);
        Route::get('/stats/top', [StatsController::class, 'top']);
        Route::get('/stats/graph', [StatsController::class, 'graph']);
        Route::get('/stats/breakdown/{dim}', [StatsController::class, 'breakdown']);
        Route::get('/stats/goals', [StatsController::class, 'goals']);
        Route::get('/segments', [SegmentController::class, 'index']);
        Route::get('/annotations', [AnnotationController::class, 'index']);
        Route::get('/stats/journeys', [StatsController::class, 'journeys']);
        Route::get('/stats/funnels', [StatsController::class, 'funnels']);
        Route::get('/stats/funnels/{funnel}', [StatsController::class, 'funnel']);
        Route::get('/stats/props', [StatsController::class, 'props']);
        Route::get('/stats/props/{key}', [StatsController::class, 'propValues'])->where('key', '.+');
        Route::get('/stats/current-visitors', [StatsController::class, 'currentVisitors']);
        Route::get('/stats/export', [StatsController::class, 'export']);
    });
});

// ---- authenticated ---------------------------------------------------------------------------------------------
Route::middleware('api.auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1');
    Route::post('/me', [AuthController::class, 'update']);
    Route::post('/me/password', [AuthController::class, 'password']);
    Route::post('/me/two-factor', [AuthController::class, 'twoFactorSetup']);
    Route::post('/me/two-factor/confirm', [AuthController::class, 'twoFactorConfirm']);
    Route::delete('/me/two-factor', [AuthController::class, 'twoFactorDisable']);
    Route::get('/me/reports', [ReportSubscriptionController::class, 'index']);
    Route::put('/me/reports/{site}', [ReportSubscriptionController::class, 'update']);
    Route::get('/me/keys', [AuthController::class, 'keys']);
    Route::post('/me/keys', [AuthController::class, 'createKey']);
    Route::delete('/me/keys/{id}', [AuthController::class, 'deleteKey']);

    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::patch('/teams/{team}', [TeamController::class, 'update']);
    Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
    Route::post('/teams/{team}/members', [TeamController::class, 'addMember']);
    Route::delete('/teams/{team}/members/{user}', [TeamController::class, 'removeMember']);
    Route::post('/teams/{team}/invites/{invite}/resend', [TeamController::class, 'resendInvite'])->middleware('throttle:10,1');
    Route::delete('/teams/{team}/invites/{invite}', [TeamController::class, 'revokeInvite']);
    Route::get('/rollups/candidates', [RollupController::class, 'candidates']);
    Route::post('/rollups', [RollupController::class, 'store']);
    Route::get('/sites', [SiteController::class, 'index']);
    Route::post('/sites', [SiteController::class, 'store']);

    // Anyone with access to a site (viewers included) may keep personal segments; owners and admins may also share them with everyone.
    Route::middleware('site:view')->prefix('/sites/{site}')->group(function () {
        Route::get('/favicon', [FaviconController::class, 'show']);
        Route::post('/annotations', [AnnotationController::class, 'store']);
        Route::patch('/annotations/{annotation}', [AnnotationController::class, 'update']);
        Route::delete('/annotations/{annotation}', [AnnotationController::class, 'destroy']);
        Route::post('/segments', [SegmentController::class, 'store']);
        Route::patch('/segments/{segment}', [SegmentController::class, 'update']);
        Route::delete('/segments/{segment}', [SegmentController::class, 'destroy']);
    });

    Route::middleware('site:manage')->prefix('/sites/{site}')->group(function () {
        Route::patch('/', [SiteController::class, 'update']);
        Route::put('/rollup', [RollupController::class, 'update']);
        Route::patch('/branding', [BrandingController::class, 'update']);
        Route::post('/branding/logo', [BrandingController::class, 'uploadLogo']);
        Route::delete('/branding/logo', [BrandingController::class, 'deleteLogo']);
        Route::post('/reset', [SiteController::class, 'reset']);
        Route::get('/export/data', [DataController::class, 'export']);
        Route::post('/import', [DataController::class, 'import']);
        Route::post('/import/plausible/start', [PlausibleImportController::class, 'start']);
        Route::post('/import/plausible', [PlausibleImportController::class, 'store']);
        Route::post('/import/plausible/finish', [PlausibleImportController::class, 'finish']);
        Route::delete('/import/plausible', [PlausibleImportController::class, 'destroy']);
        Route::get('/goals', [S::class, 'goals']);
        Route::post('/goals', [S::class, 'createGoal']);
        Route::patch('/goals/{goal}', [S::class, 'updateGoal']);
        Route::delete('/goals/{goal}', [S::class, 'deleteGoal']);
        Route::get('/funnels', [S::class, 'funnels']);
        Route::post('/funnels', [S::class, 'createFunnel']);
        Route::put('/funnels/{funnel}', [S::class, 'updateFunnel']);
        Route::delete('/funnels/{funnel}', [S::class, 'deleteFunnel']);
        Route::get('/shared-links', [S::class, 'sharedLinks']);
        Route::post('/shared-links', [S::class, 'createSharedLink']);
        Route::patch('/shared-links/{link}', [S::class, 'updateSharedLink']);
        Route::delete('/shared-links/{link}', [S::class, 'deleteSharedLink']);
        Route::get('/alerts', [AlertController::class, 'index']);
        Route::post('/alert-rules', [AlertController::class, 'storeRule']);
        Route::patch('/alert-rules/{rule}', [AlertController::class, 'updateRule']);
        Route::delete('/alert-rules/{rule}', [AlertController::class, 'destroyRule']);
        Route::post('/alert-webhooks', [AlertController::class, 'storeWebhook']);
        Route::patch('/alert-webhooks/{webhook}', [AlertController::class, 'updateWebhook']);
        Route::delete('/alert-webhooks/{webhook}', [AlertController::class, 'destroyWebhook']);
        Route::post('/alert-webhooks/{webhook}/test', [AlertController::class, 'testWebhook'])->middleware('throttle:10,1');
        Route::get('/pii-masks', [S::class, 'piiMasks']);
        Route::get('/filtered', [S::class, 'filtered']);
        Route::get('/shields', [S::class, 'shields']);
        Route::post('/shields', [S::class, 'createShield']);
        Route::delete('/shields/{rule}', [S::class, 'deleteShield']);
        Route::get('/people', [S::class, 'people']);
        Route::post('/people', [S::class, 'addPerson']);
        Route::patch('/people/{user}', [S::class, 'updatePerson']);
        Route::delete('/people/{user}', [S::class, 'removePerson']);
        Route::post('/invites/{invite}/resend', [S::class, 'resendInvite'])->middleware('throttle:10,1');
        Route::delete('/invites/{invite}', [S::class, 'revokeInvite']);
    });
    Route::middleware('site:owner')->prefix('/sites/{site}')->group(function () {
        Route::post('/teams', [S::class, 'addTeam']);
        Route::patch('/teams/{team}', [S::class, 'updateTeam']);
        Route::delete('/teams/{team}', [S::class, 'removeTeam']);
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

    // MCP server (read-only tools over the Stats API)
    Route::post('/mcp', [McpController::class, 'handle'])->middleware('throttle:120,1');
    Route::get('/mcp', [McpController::class, 'stream']);

    // Stats API v1 (Plausible-compatible subset)
    Route::prefix('/v1/stats')->group(function () {
        Route::get('/realtime/visitors', [StatsApiController::class, 'realtime']);
        Route::get('/aggregate', [StatsApiController::class, 'aggregate']);
        Route::get('/timeseries', [StatsApiController::class, 'timeseries']);
        Route::get('/breakdown', [StatsApiController::class, 'breakdown']);
        Route::get('/annotations', [StatsApiController::class, 'annotations']);
        Route::get('/goals', [StatsApiController::class, 'goals']);
        Route::get('/funnels', [StatsApiController::class, 'funnels']);
        Route::get('/funnels/{funnel}', [StatsApiController::class, 'funnel']);
    });
});
