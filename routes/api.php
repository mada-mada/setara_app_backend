<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\UserController;

Route::get('/places', [PlaceController::class, 'index']);
Route::get('/places/{id}', [PlaceController::class, 'show']);
Route::post('/places', [PlaceController::class, 'store']);

Route::post('/orders', [OrderController::class, 'store']);

Route::post('/projects', [ProjectController::class, 'store']);
Route::post('/users', [UserController::class, 'store']);

Route::post('/menus/{placeId}', [MenuController::class, 'store']);
Route::get('/menus/{placeId}', [MenuController::class, 'index']);

