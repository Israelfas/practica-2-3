<?php

namespace App\Services\Normalizacion;

use App\DTOs\ProductoDanadoNormalizado;
use App\Exceptions\DatosNoNormalizablesException;
use App\Http\Requests\ProductRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Traductor (Message Translator / Normalizer) de la práctica 2.3.
 *
 * Recibe el mismo hecho —"llegó un producto dañado a una agencia"— en dos
 * formatos distintos y lo convierte al contrato común del backend del
 * proyecto de aula: el cuerpo de POST /api/products (ProductRequest).
 *
 *  - Origen A: formulario web de la agencia (React). Plano, camelCase en
 *    español, todo texto, precio con coma decimal, "Leve", publicar "Sí"/"No".
 *  - Origen B: servicio externo de rastreo (simulado). Anidado, snake_case en
 *    inglés, tipos numéricos y booleanos, nivel de daño codificado 1..3.
 *
 * Regla general: se limpian diferencias de forma (espacios, mayúsculas,
 * tildes) pero NUNCA se adivina un valor ambiguo o inválido; en ese caso se
 * acumula un error explícito y se rechaza la entrada completa.
 */
class NormalizadorProductoDanado
{
    public const ORIGEN_WEB = 'web';
    public const ORIGEN_RASTREO = 'rastreo';

    /** severity_code del servicio de rastreo => damageLevel del contrato. */
    private const NIVELES_RASTREO = [1 => 'leve', 2 => 'moderado', 3 => 'severo'];

    private const NIVELES = ['leve', 'moderado', 'severo'];

    private const MONEDA = 'USD';

    private const STOCK_MAX = 999;

    /** @var array<string, list<string>> */
    private array $errores = [];

    /** Punto de entrada único: elige el método de traducción según el origen. */
    public function normalizar(string $origen, array $entrada): ProductoDanadoNormalizado
    {
        return match ($origen) {
            self::ORIGEN_WEB     => $this->desdeFormularioWeb($entrada),
            self::ORIGEN_RASTREO => $this->desdeServicioRastreo($entrada),
            default              => throw new DatosNoNormalizablesException($origen, [
                'origen' => ["El origen '{$origen}' no está soportado. Use: web, rastreo."],
            ]),
        };
    }

    /**
     * Origen A – formulario web de agencia.
     * {"codigoGuia","nombreProducto","descripcion"?,"gradoDanio","detalleDanio"?,"precioVenta","unidades","publicar"}
     */
    public function desdeFormularioWeb(array $e): ProductoDanadoNormalizado
    {
        $this->errores = [];

        $contrato = [
            'name'        => $this->nombre($e, 'nombreProducto'),
            'description' => $this->textoOpcional($e, 'descripcion'),
            'price'       => $this->precioDesdeTextoConComa($e, 'precioVenta'),
            'stock'       => $this->stockDesdeTexto($e, 'unidades'),
            'sku'         => $this->codigoGuia($e, 'codigoGuia'),
            'damageLevel' => $this->nivelDesdeTexto($e, 'gradoDanio'),
            'damageNotes' => $this->textoOpcional($e, 'detalleDanio'),
            'status'      => $this->estadoDesdeSiNo($e, 'publicar'),
        ];

        return $this->construir(self::ORIGEN_WEB, $contrato);
    }

    /**
     * Origen B – servicio externo de rastreo.
     * {"tracking_number","item":{"title","details"?,"quantity"},"damage":{"severity_code","notes"?},
     *  "pricing":{"amount","currency"},"available_for_sale", ...campos que se descartan}
     */
    public function desdeServicioRastreo(array $e): ProductoDanadoNormalizado
    {
        $this->errores = [];

        $this->exigirMonedaUsd($e, 'pricing.currency');

        $contrato = [
            'name'        => $this->nombre($e, 'item.title'),
            'description' => $this->textoOpcional($e, 'item.details'),
            'price'       => $this->precioDesdeNumero($e, 'pricing.amount'),
            'stock'       => $this->stockDesdeEntero($e, 'item.quantity'),
            'sku'         => $this->codigoGuia($e, 'tracking_number'),
            'damageLevel' => $this->nivelDesdeCodigo($e, 'damage.severity_code'),
            'damageNotes' => $this->textoOpcional($e, 'damage.notes'),
            'status'      => $this->estadoDesdeBooleano($e, 'available_for_sale'),
        ];

        return $this->construir(self::ORIGEN_RASTREO, $contrato);
    }

    // ---------------------------------------------------------------------
    // Construcción y validación final contra el contrato del backend
    // ---------------------------------------------------------------------

    private function construir(string $origen, array $contrato): ProductoDanadoNormalizado
    {
        if ($this->errores !== []) {
            throw new DatosNoNormalizablesException($origen, $this->errores);
        }

        // Segunda barrera: se validan las MISMAS reglas de POST /api/products
        // (ProductRequest), sin copiarlas ni cambiarlas. Incluye que el sku no exista.
        $validador = Validator::make($contrato, (new ProductRequest())->rules(), [
            'sku.unique' => 'El producto con sku :input ya está registrado.',
        ]);

        if ($validador->fails()) {
            throw new DatosNoNormalizablesException($origen, $validador->errors()->toArray());
        }

        return new ProductoDanadoNormalizado(...$contrato);
    }

    // ---------------------------------------------------------------------
    // Reglas compartidas por ambos orígenes
    // ---------------------------------------------------------------------

    /** Número de guía => sku. Mayúsculas y sin espacios; formato UPU S10: EC123456789EC. */
    private function codigoGuia(array $e, string $campo): ?string
    {
        $valor = $this->texto($e, $campo);
        if ($valor === null) {
            return null;
        }

        $codigo = strtoupper($valor);
        if (! preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $codigo)) {
            return $this->error($campo, "El número de guía '{$valor}' no tiene el formato EC123456789EC.");
        }

        return $codigo;
    }

    /** Recorta y colapsa espacios internos; no cambia mayúsculas ni contenido. */
    private function nombre(array $e, string $campo): ?string
    {
        $valor = $this->texto($e, $campo);
        if ($valor === null) {
            return null;
        }

        $nombre = preg_replace('/\s+/u', ' ', $valor);
        $largo = mb_strlen($nombre);
        if ($largo < 3 || $largo > 150) {
            return $this->error($campo, 'El nombre del producto debe tener entre 3 y 150 caracteres.');
        }

        return $nombre;
    }

    /** Campo opcional: ausente o vacío => null; si viene, debe ser texto. */
    private function textoOpcional(array $e, string $campo): ?string
    {
        $valor = data_get($e, $campo);
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        if (! is_string($valor)) {
            return $this->error($campo, "El campo {$campo} debe ser texto.");
        }

        return preg_replace('/\s+/u', ' ', trim($valor));
    }

    // ---------------------------------------------------------------------
    // Reglas propias del origen A (formulario web)
    // ---------------------------------------------------------------------

    /** "Leve", "MODERADO", " severo " => leve | moderado | severo. Sin sinónimos. */
    private function nivelDesdeTexto(array $e, string $campo): ?string
    {
        $valor = $this->texto($e, $campo);
        if ($valor === null) {
            return null;
        }

        $nivel = Str::lower(Str::ascii($valor));
        if (! in_array($nivel, self::NIVELES, true)) {
            return $this->error($campo, "El grado de daño '{$valor}' no es válido. Use: Leve, Moderado o Severo.");
        }

        return $nivel;
    }

    /**
     * "24,50" => 24.5. Solo coma decimal y sin separador de miles:
     * "24.50" o "1.250,00" son ambiguos en este origen y se rechazan.
     */
    private function precioDesdeTextoConComa(array $e, string $campo): ?float
    {
        $valor = $this->texto($e, $campo);
        if ($valor === null) {
            return null;
        }

        if (! preg_match('/^\d{1,6}(,\d{1,2})?$/', $valor)) {
            $detalle = str_contains($valor, '.')
                ? ' El formulario usa coma decimal; el punto es ambiguo (¿decimal o miles?).'
                : '';

            return $this->error($campo, "El precio '{$valor}' no es válido. Formato esperado: 24,50.{$detalle}");
        }

        $precio = (float) str_replace(',', '.', $valor);
        if ($precio <= 0) {
            return $this->error($campo, 'El precio debe ser mayor que 0.');
        }

        return $precio;
    }

    /** "5" => 5. Rechaza "5.0", "cinco", "-1". */
    private function stockDesdeTexto(array $e, string $campo): ?int
    {
        $valor = $this->texto($e, $campo);
        if ($valor === null) {
            return null;
        }

        if (! ctype_digit($valor)) {
            return $this->error($campo, "Las unidades '{$valor}' deben ser un número entero sin decimales.");
        }

        return $this->stockEnRango((int) $valor, $campo);
    }

    /** "Sí" => published, "No" => draft. Cualquier otra respuesta es ambigua. */
    private function estadoDesdeSiNo(array $e, string $campo): ?string
    {
        $valor = $this->texto($e, $campo);
        if ($valor === null) {
            return null;
        }

        return match (Str::lower(Str::ascii($valor))) {
            'si'    => 'published',
            'no'    => 'draft',
            default => $this->error($campo, "El valor '{$valor}' no es válido. Use: Sí o No."),
        };
    }

    // ---------------------------------------------------------------------
    // Reglas propias del origen B (servicio de rastreo)
    // ---------------------------------------------------------------------

    /** 1 => leve, 2 => moderado, 3 => severo. Debe ser entero, no "1". */
    private function nivelDesdeCodigo(array $e, string $campo): ?string
    {
        $valor = $this->requerido($e, $campo);
        if ($valor === null) {
            return null;
        }

        if (! is_int($valor) || ! isset(self::NIVELES_RASTREO[$valor])) {
            return $this->error($campo, 'El código de severidad debe ser el entero 1, 2 o 3.');
        }

        return self::NIVELES_RASTREO[$valor];
    }

    /** 24.5 => 24.5. Debe ser número JSON (no texto) con máximo 2 decimales. */
    private function precioDesdeNumero(array $e, string $campo): ?float
    {
        $valor = $this->requerido($e, $campo);
        if ($valor === null) {
            return null;
        }

        if (! is_int($valor) && ! is_float($valor)) {
            return $this->error($campo, 'El monto debe ser un número JSON, no texto.');
        }
        if ($valor <= 0) {
            return $this->error($campo, 'El monto debe ser mayor que 0.');
        }
        if (abs(round($valor * 100) - $valor * 100) > 1e-6) {
            return $this->error($campo, 'El monto no puede tener más de 2 decimales.');
        }

        return round((float) $valor, 2);
    }

    /** El contrato maneja precios en USD: otra moneda se rechaza, no se convierte. */
    private function exigirMonedaUsd(array $e, string $campo): void
    {
        $valor = $this->texto($e, $campo);
        if ($valor !== null && strtoupper($valor) !== self::MONEDA) {
            $this->error($campo, "La moneda '{$valor}' no está soportada; el catálogo solo maneja USD.");
        }
    }

    private function stockDesdeEntero(array $e, string $campo): ?int
    {
        $valor = $this->requerido($e, $campo);
        if ($valor === null) {
            return null;
        }

        if (! is_int($valor)) {
            return $this->error($campo, 'La cantidad debe ser un entero JSON.');
        }

        return $this->stockEnRango($valor, $campo);
    }

    /** true => published, false => draft. Debe ser booleano JSON, no "true" ni 1. */
    private function estadoDesdeBooleano(array $e, string $campo): ?string
    {
        $valor = data_get($e, $campo);
        if ($valor === null) {
            return $this->error($campo, "El campo {$campo} es obligatorio.");
        }
        if (! is_bool($valor)) {
            return $this->error($campo, 'El campo available_for_sale debe ser true o false.');
        }

        return $valor ? 'published' : 'draft';
    }

    // ---------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------

    /** Un producto recibido tiene al menos 1 unidad (el contrato acepta 0, el traductor es más estricto). */
    private function stockEnRango(int $stock, string $campo): ?int
    {
        if ($stock < 1 || $stock > self::STOCK_MAX) {
            return $this->error($campo, 'Las unidades deben estar entre 1 y ' . self::STOCK_MAX . '.');
        }

        return $stock;
    }

    /** Devuelve el valor (ruta con puntos para campos anidados) o registra "obligatorio". */
    private function requerido(array $e, string $campo): mixed
    {
        $valor = data_get($e, $campo);
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return $this->error($campo, "El campo {$campo} es obligatorio.");
        }

        return $valor;
    }

    /** Como requerido(), pero además exige texto y lo devuelve recortado. */
    private function texto(array $e, string $campo): ?string
    {
        $valor = $this->requerido($e, $campo);
        if ($valor === null) {
            return null;
        }
        if (! is_string($valor)) {
            return $this->error($campo, "El campo {$campo} debe ser texto.");
        }

        return trim($valor);
    }

    /** Registra el error y devuelve null para que el campo quede sin valor. */
    private function error(string $campo, string $mensaje): null
    {
        $this->errores[$campo][] = $mensaje;

        return null;
    }
}
