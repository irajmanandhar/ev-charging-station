<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
    'docs' => 'See README.md for the API reference.',
]));
