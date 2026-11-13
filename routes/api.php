<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BaleAccountController;
use App\Http\Controllers\Api\V1\BaleAuthController;
use App\Http\Controllers\Api\V1\BaleFileController;
use App\Http\Controllers\Api\V1\BaleOperationController;
use App\Http\Controllers\Api\V1\MediaAssetController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use Dedoc\Scramble\Attributes\Endpoint;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::get(
        '/health',
        #[Endpoint(operationId: 'healthCheck', title: 'Check API health', description: 'Returns the basic API availability status.')]
        static fn () => response()->json(['status' => 'ok']),
    );

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/bale/methods', [BaleOperationController::class, 'index']);
        Route::post('/bale/auth/start', [BaleAuthController::class, 'start'])->middleware('throttle:bale-auth');
        Route::post('/bale/auth/challenges/{challenge}/verify-code', [BaleAuthController::class, 'verifyCode'])->middleware('throttle:bale-auth');
        Route::post('/bale/auth/challenges/{challenge}/verify-password', [BaleAuthController::class, 'verifyPassword'])->middleware('throttle:bale-auth');
        Route::post('/bale/auth/challenges/{challenge}/sign-up', [BaleAuthController::class, 'signUp'])->middleware('throttle:bale-auth');

        Route::get('/bale/accounts', [BaleAccountController::class, 'index']);
        Route::get('/bale/accounts/{account}', [BaleAccountController::class, 'show']);
        Route::patch('/bale/accounts/{account}', [BaleAccountController::class, 'update']);
        Route::delete('/bale/accounts/{account}', [BaleAccountController::class, 'destroy']);
        Route::post('/bale/accounts/{account}/sign-out', [BaleAccountController::class, 'signOut']);
        Route::post('/bale/accounts/{account}/files', [BaleFileController::class, 'store']);
        Route::post('/bale/accounts/{account}/methods/{method}', [BaleOperationController::class, 'invoke'])
            ->where('method', '[A-Za-z][A-Za-z0-9_.]*');

        Route::apiResource('/webhooks', WebhookEndpointController::class)->except(['show']);
        Route::get('/webhooks/{webhook}/deliveries', [WebhookEndpointController::class, 'deliveries']);

        Route::get('/media', [MediaAssetController::class, 'index']);
        Route::post('/media', [MediaAssetController::class, 'store']);
        Route::get('/media/{media}/download', [MediaAssetController::class, 'download'])->name('media.download');
        Route::delete('/media/{media}', [MediaAssetController::class, 'destroy']);
    });
});
