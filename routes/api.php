<?php

use Illuminate\Support\Facades\Route;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoAuthController;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoMobileController;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoSignController;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoTimestampController;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoVerifyController;

// Stateless variants of the same routes for SPA / mobile clients.
Route::get('auth/challenge', [EimzoAuthController::class, 'challenge'])->name('auth.challenge');
Route::post('auth/verify', [EimzoAuthController::class, 'verify'])->name('auth.verify');
Route::post('auth/logout', [EimzoAuthController::class, 'logout'])->name('auth.logout');

Route::post('sign', [EimzoSignController::class, 'store'])->name('sign.store');
Route::get('signatures/{signature}', [EimzoSignController::class, 'show'])->name('sign.show');

Route::post('verify', [EimzoVerifyController::class, 'verify'])->name('verify.store');

Route::post('timestamp/pkcs7', [EimzoTimestampController::class, 'pkcs7'])->name('timestamp.pkcs7');
Route::post('timestamp/data', [EimzoTimestampController::class, 'data'])->name('timestamp.data');
Route::post('pkcs7/make-attached', [EimzoTimestampController::class, 'makeAttached'])->name('pkcs7.make-attached');
Route::post('pkcs7/join', [EimzoTimestampController::class, 'join'])->name('pkcs7.join');

if (config('eimzo.mobile.enabled', true)) {
    Route::post('mobile/auth/start', [EimzoMobileController::class, 'authStart'])->name('mobile.auth.start');
    Route::post('mobile/auth/status', [EimzoMobileController::class, 'status'])->name('mobile.auth.status');
    Route::post('mobile/auth/complete', [EimzoMobileController::class, 'authComplete'])->name('mobile.auth.complete');
    Route::post('mobile/sign/start', [EimzoMobileController::class, 'signStart'])->name('mobile.sign.start');
    Route::post('mobile/sign/status', [EimzoMobileController::class, 'status'])->name('mobile.sign.status');
    Route::post('mobile/sign/complete', [EimzoMobileController::class, 'signComplete'])->name('mobile.sign.complete');
    Route::match(['get', 'post'], 'mobile/upload', [EimzoMobileController::class, 'upload'])->name('mobile.upload');
}
