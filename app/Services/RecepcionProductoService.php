<?php

namespace App\Services;

use App\DTOs\ProductoDanadoNormalizado;
use App\Models\DamagedProduct;

/**
 * Registra en damaged_products un producto que YA viene en el contrato común.
 * Hace lo mismo que ProductController::store del backend; no sabe nada de los formatos de origen.
 */
class RecepcionProductoService
{
    public function registrar(ProductoDanadoNormalizado $dto): DamagedProduct
    {
        return DamagedProduct::create($dto->attributesForModel());
    }
}
