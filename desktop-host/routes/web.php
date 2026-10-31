<?php

use App\Http\Controllers\HostController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HostController::class, 'index']);
Route::get('/host/status', [HostController::class, 'status']);
Route::post('/host/start', [HostController::class, 'start']);
Route::post('/host/stop', [HostController::class, 'stop']);
Route::post('/host/settings', [HostController::class, 'save']);
