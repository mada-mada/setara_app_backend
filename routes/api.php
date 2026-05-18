<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\UserController;

Route::get('/places', [PlaceController::class, 'index']);          
Route::post('/places', [PlaceController::class, 'store']);          
Route::put('/places/{id}', [PlaceController::class, 'update']);   
Route::delete('/places/{id}', [PlaceController::class, 'destroy']); 

Route::get('/places/{placeId}/menus', [MenuController::class, 'index']);             
Route::post('/places/{placeId}/menus', [MenuController::class, 'store']);              
Route::get('/places/{placeId}/menus/{menuId}', [MenuController::class, 'show']);        
Route::put('/places/{placeId}/menus/{menuId}', [MenuController::class, 'update']);      
Route::delete('/places/{placeId}/menus/{menuId}', [MenuController::class, 'destroy']); 


Route::get('/orders', [OrderController::class, 'index']);   
Route::post('/orders', [OrderController::class, 'store']);  

Route::get('/users', [UserController::class, 'index']);   
Route::post('/users', [UserController::class, 'store']);  


Route::post('/projects', [ProjectController::class, 'store']); 
