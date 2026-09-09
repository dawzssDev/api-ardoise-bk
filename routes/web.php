<?php

use App\Http\Controllers\EmpleadoImageController;
use App\Http\Controllers\NegocioLogoController;
use App\Http\Controllers\ProductoImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Imágenes de productos (sin auth; no usa /api)
Route::get('/productos/{negocioId}/{filename}', [ProductoImageController::class, 'show'])
    ->whereNumber('negocioId')
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('productos.image');

// Imágenes de empleados (sin auth; no usa /api)
Route::get('/empleados/{negocioId}/{filename}', [EmpleadoImageController::class, 'show'])
    ->whereNumber('negocioId')
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('empleados.image');

// Logos de negocio (sin auth; no usa /api)
Route::get('/NegociosLogos/{filename}', [NegocioLogoController::class, 'show'])
    ->where('filename', '[A-Za-z0-9._-]+')
    ->name('negocios.logo');
