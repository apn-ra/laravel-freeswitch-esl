<?php

use ApnTalk\LaravelFreeswitchEsl\Http\Controllers\HealthSnapshotController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('freeswitch-esl.http.health.middleware', []))
    ->prefix(config('freeswitch-esl.http.health.prefix', 'freeswitch-esl/health'))
    ->group(function (): void {
        Route::get('/', [HealthSnapshotController::class, 'summary'])
            ->name('freeswitch-esl.health.summary');
        Route::get('/live', [HealthSnapshotController::class, 'liveness'])
            ->name('freeswitch-esl.health.liveness');
        Route::get('/ready', [HealthSnapshotController::class, 'readiness'])
            ->name('freeswitch-esl.health.readiness');
    });
