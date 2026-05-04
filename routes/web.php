<?php

use Illuminate\Support\Facades\Route;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoAuthController;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoDemoController;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoMobileController;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoSignController;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoTimestampController;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoVerifyController;

Route::get('/', [EimzoDemoController::class, 'index'])->name('index');
Route::get('login', [EimzoDemoController::class, 'login'])->name('login.form');
Route::get('sign', [EimzoDemoController::class, 'sign'])->name('sign.form');
Route::get('verify', [EimzoDemoController::class, 'verify'])->name('verify.form');
Route::get('examples', [EimzoDemoController::class, 'examples'])->name('examples.index');
Route::get('examples/login', [EimzoDemoController::class, 'loginExample'])->name('examples.login');
Route::get('examples/document-sign', [EimzoDemoController::class, 'documentSignExample'])->name('examples.document-sign');
Route::get('examples/action-sign', [EimzoDemoController::class, 'actionSignExample'])->name('examples.action-sign');

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

Route::post('mobile/auth/start', [EimzoMobileController::class, 'authStart'])->name('mobile.auth.start');
Route::post('mobile/auth/status', [EimzoMobileController::class, 'status'])->name('mobile.auth.status');
Route::post('mobile/auth/complete', [EimzoMobileController::class, 'authComplete'])->name('mobile.auth.complete');
Route::post('mobile/sign/start', [EimzoMobileController::class, 'signStart'])->name('mobile.sign.start');
Route::post('mobile/sign/status', [EimzoMobileController::class, 'status'])->name('mobile.sign.status');
Route::post('mobile/sign/complete', [EimzoMobileController::class, 'signComplete'])->name('mobile.sign.complete');
// The ID-CARD app cannot send a CSRF token. Excluded from VerifyCsrfToken
// in the consuming app (see USAGE.md), or move under api/eimzo for stateless.
Route::match(['get', 'post'], 'mobile/upload', [EimzoMobileController::class, 'upload'])->name('mobile.upload');
Route::match(['get', 'post'], 'frontend/mobile/upload', [EimzoMobileController::class, 'upload'])->name('mobile.upload.alias');
