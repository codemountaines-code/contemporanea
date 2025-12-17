<?php

use Illuminate\Support\Facades\Route;
require __DIR__ . '/twilio.php';

Route::get('/', function () {
    return view('welcome');
});
