<?php

use App\Http\Controllers\CoverageController;
use App\Http\Controllers\HotelController;
use App\Http\Controllers\HotelDiscoveryController;
use App\Http\Controllers\HotelOnboardingController;
use App\Http\Controllers\HotelPhotoController;
use App\Http\Controllers\HotelStaffController;
use App\Http\Controllers\ManualCatalogController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PublicHotelController;
use App\Http\Controllers\PublicRatePlanController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\PrivateApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): JsonResponse {
    return response()->json(['service' => 'niwadu-api']);
});

Route::prefix('api/v1')->middleware([PrivateApiResponse::class, 'auth.session'])->group(function () {
    Route::get('public/hotels/{slug}/rate-plans', [PublicRatePlanController::class, 'index'])->middleware('throttle:60,1');
    Route::get('public/hotels', [PublicHotelController::class, 'index']);
    Route::get('public/hotels/{slug}', [PublicHotelController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
    Route::get('public/discovery-options', [PublicHotelController::class, 'options']);
    Route::get('session', [SessionController::class, 'show']);
    Route::post('register', [SessionController::class, 'register'])->middleware('throttle:5,1');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::post('password/setup', [PasswordController::class, 'store'])->middleware('throttle:10,1');
    Route::middleware('auth')->group(function () {
        Route::get('me/coverage', [CoverageController::class, 'show']);
        Route::put('me/coverage', [CoverageController::class, 'update'])->middleware('throttle:60,1');
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::get('hotels', [HotelController::class, 'index']);
        Route::post('hotels', [HotelController::class, 'store']);
        Route::get('hotels/{hotel}/discovery-review', [HotelDiscoveryController::class, 'review']);
        Route::put('hotels/{hotel}/discovery', [HotelDiscoveryController::class, 'release']);
        Route::delete('hotels/{hotel}/discovery', [HotelDiscoveryController::class, 'withdraw']);
        Route::get('hotels/{hotel}/photos', [HotelPhotoController::class, 'index']);
        Route::post('hotels/{hotel}/photos', [HotelPhotoController::class, 'store'])->middleware('throttle:30,1');
        Route::get('hotels/{hotel}/photos/{photo}', [HotelPhotoController::class, 'show'])->name('hotel-photos.show');
        Route::post('hotels/{hotel}/room-types', [ManualCatalogController::class, 'saveRoom']);
        Route::put('hotels/{hotel}/room-types/{room}', [ManualCatalogController::class, 'saveRoom'])->whereNumber('room');
        Route::put('hotels/{hotel}/room-types/{room}/inventory-pool', [ManualCatalogController::class, 'pool'])->whereNumber(['room', 'plan']);
        Route::get('hotels/{hotel}/room-types/{room}/rate-plans', [ManualCatalogController::class, 'plans'])->whereNumber(['room', 'plan']);
        Route::post('hotels/{hotel}/room-types/{room}/rate-plans', [ManualCatalogController::class, 'savePlan'])->whereNumber(['room', 'plan']);
        Route::put('hotels/{hotel}/room-types/{room}/rate-plans/{plan}', [ManualCatalogController::class, 'savePlan'])->whereNumber(['room', 'plan']);
        Route::get('hotels/{hotel}/room-types/{room}/inventory-nights', [ManualCatalogController::class, 'stockCalendar'])->whereNumber(['room', 'plan']);
        Route::put('hotels/{hotel}/room-types/{room}/inventory-nights/{date}', [ManualCatalogController::class, 'saveStock'])->whereNumber(['room', 'plan']);
        Route::get('hotels/{hotel}/room-types/{room}/rate-plans/{plan}/nights', [ManualCatalogController::class, 'rateCalendar'])->whereNumber(['room', 'plan']);
        Route::put('hotels/{hotel}/room-types/{room}/rate-plans/{plan}/nights/{date}', [ManualCatalogController::class, 'saveRate'])->whereNumber(['room', 'plan']);
        Route::get('hotels/{hotel}/room-types', [RoomTypeController::class, 'index']);
        Route::get('hotels/{hotel}/room-types/{room}', [RoomTypeController::class, 'show'])->whereNumber('room');
        Route::delete('hotels/{hotel}/photos/{photo}', [HotelPhotoController::class, 'destroy']);
        Route::get('hotels/{hotel}/onboarding', [HotelOnboardingController::class, 'show']);
        Route::patch('hotels/{hotel}/onboarding', [HotelOnboardingController::class, 'update']);
        Route::get('hotels/{hotel}', [HotelController::class, 'show']);
        Route::patch('hotels/{hotel}', [HotelController::class, 'update']);
        Route::get('hotels/{hotel}/staff', [HotelStaffController::class, 'index']);
        Route::post('hotels/{hotel}/staff', [HotelStaffController::class, 'store'])->middleware('throttle:30,1');
        Route::post('hotels/{hotel}/staff/{user}/password-link', [HotelStaffController::class, 'passwordLink'])->middleware('throttle:10,1');
        Route::delete('hotels/{hotel}/staff/{user}', [HotelStaffController::class, 'destroy']);
    });
});
