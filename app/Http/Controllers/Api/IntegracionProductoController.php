<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Services\Normalizacion\NormalizadorProductoDanado;
use App\Services\RecepcionProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El controlador no traduce ni valida campos: solo entrega el JSON crudo al
 * traductor y trabaja con el contrato común que este devuelve.
 */
class IntegracionProductoController extends Controller
{
    public function __construct(
        private readonly NormalizadorProductoDanado $normalizador,
        private readonly RecepcionProductoService $recepcion,
    ) {}

    // POST /api/integracion/{origen}/normalizar -> solo traduce al contrato, no guarda
    public function normalizar(Request $request, string $origen): JsonResponse
    {
        $dto = $this->normalizador->normalizar($origen, $request->json()->all());

        return response()->json([
            'origen' => $origen,
            'data'   => $dto->toArray(),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }

    // POST /api/integracion/{origen}/productos -> traduce y registra en damaged_products
    public function registrar(Request $request, string $origen): JsonResponse
    {
        $dto = $this->normalizador->normalizar($origen, $request->json()->all());
        $producto = $this->recepcion->registrar($dto);

        // Misma respuesta que POST /api/products del backend (ProductResource)
        return (new ProductResource($producto))
            ->additional(['origen' => $origen])
            ->response()
            ->setStatusCode(201)
            ->setEncodingOptions(JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }
}
