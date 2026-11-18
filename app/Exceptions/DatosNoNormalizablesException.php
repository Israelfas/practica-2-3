<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Se lanza cuando una entrada no se puede traducir al contrato común.
 * Lleva TODOS los errores encontrados, indexados por el campo del origen,
 * para que el componente emisor sepa exactamente qué corregir.
 */
class DatosNoNormalizablesException extends InvalidArgumentException
{
    /** @param array<string, list<string>> $errores */
    public function __construct(
        public readonly string $origen,
        public readonly array $errores,
    ) {
        parent::__construct("Los datos del origen '{$origen}' no se pudieron normalizar al contrato común.");
    }

    /** Laravel llama a este método y responde 422 con el mismo formato de errores de la práctica 2.2. */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'origen'  => $this->origen,
            'errors'  => $this->errores,
        ], 422);
    }
}
