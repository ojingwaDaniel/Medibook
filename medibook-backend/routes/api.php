<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\DoctorController;
use App\Http\Controllers\Api\V1\SpecialtyController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\Doctor\AvailabilityController;
use App\Http\Controllers\Api\V1\Doctor\ProfileController;
use App\Http\Controllers\Api\V1\Doctor\ScheduleController;
use App\Http\Controllers\Api\V1\Doctor\TimeOffController;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('auth/register-doctor', [AuthController::class, 'registerDoctor'])->middleware('throttle:10,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('specialties', [SpecialtyController::class, 'index']);
        Route::get('doctors', [DoctorController::class, 'index']);
        Route::get('doctors/{doctor}', [DoctorController::class, 'show']);
        Route::get('doctors/{doctor}/slots', [DoctorController::class, 'slots']);
        Route::middleware('role:patient')->group(function () {
            Route::get('appointments', [AppointmentController::class, 'index']);
            Route::post('appointments', [AppointmentController::class, 'store'])->middleware('throttle:20,1');
            Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
            Route::patch('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
        });


        Route::middleware('role:doctor')->prefix('doctor')->group(function () {
            Route::get('profile', [ProfileController::class, 'show']);
            Route::put('profile', [ProfileController::class, 'update']);

            Route::get('availability', [AvailabilityController::class, 'show']);
            Route::put('availability', [AvailabilityController::class, 'update']);

            Route::get('time-off', [TimeOffController::class, 'index']);
            Route::post('time-off', [TimeOffController::class, 'store']);
            Route::delete('time-off/{timeOff}', [TimeOffController::class, 'destroy']);

            Route::get('appointments', [ScheduleController::class, 'index']);
            Route::patch('appointments/{appointment}/complete', [ScheduleController::class, 'complete']);
            Route::patch('appointments/{appointment}/no-show', [ScheduleController::class, 'noShow']);
            Route::patch('appointments/{appointment}/cancel', [ScheduleController::class, 'cancel']);
        });
    });
});
