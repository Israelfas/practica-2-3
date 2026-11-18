<?php

use App\Http\Controllers\Api\IntegracionProductoController;
use Illuminate\Support\Facades\Route;

// Práctica 2.3 - Marcelo Mejía: traducción y normalización hacia el contrato de POST /api/products
Route::prefix('integracion/{origen}')
    ->whereIn('origen', ['web', 'rastreo'])
    ->group(function () {
        Route::post('normalizar', [IntegracionProductoController::class, 'normalizar']);
        Route::post('productos', [IntegracionProductoController::class, 'registrar']);
    });
