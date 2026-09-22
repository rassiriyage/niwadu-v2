<?php

use App\Http\Controllers\HotelController;
use App\Http\Controllers\HotelStaffController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\PrivateApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): JsonResponse {
    return response()->json(['service' => 'niwadu-api']);
});

Route::prefix('api/v1')->middleware(PrivateApiResponse::class)->group(function () {
    Route::get('session', [SessionController::class, 'show']);
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::post('password/setup', [PasswordController::class, 'store'])->middleware('throttle:10,1');
    Route::middleware('auth')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::get('hotels', [HotelController::class, 'index']);
        Route::post('hotels', [HotelController::class, 'store']);
        Route::get('hotels/{hotel}', [HotelController::class, 'show']);
        Route::patch('hotels/{hotel}', [HotelController::class, 'update']);
        Route::get('hotels/{hotel}/staff', [HotelStaffController::class, 'index']);
        Route::post('hotels/{hotel}/staff', [HotelStaffController::class, 'store'])->middleware('throttle:30,1');
        Route::post('hotels/{hotel}/staff/{user}/password-link', [HotelStaffController::class, 'passwordLink'])->middleware('throttle:10,1');
        Route::delete('hotels/{hotel}/staff/{user}', [HotelStaffController::class, 'destroy']);
    });
});
