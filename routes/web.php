<?php

use Illuminate\Support\Facades\Route;
use Kreait\Laravel\Firebase\Facades\Firebase;


Route::get('/', function () {
    return view('welcome');
});


