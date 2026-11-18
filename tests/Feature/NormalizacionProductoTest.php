<?php

namespace Tests\Feature;

use App\Services\Normalizacion\NormalizadorProductoDanado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Práctica 2.3
 * Dos entradas heterogéneas del mismo producto deben producir el mismo contrato
 * de POST /api/products del backend del proyecto de aula.
 */
class NormalizacionProductoTest extends TestCase
{
    use RefreshDatabase;

    /** Origen A: formulario web de agencia (React). Plano y todo texto. */
    public static function entradaWeb(array $cambios = []): array
    {
        return array_merge([
            'codigoGuia'     => ' ec123456789ec ',
            'nombreProducto' => '  Licuadora   Oster - caja golpeada ',
            'descripcion'    => 'Licuadora de 1.5 L, funciona correctamente',
            'gradoDanio'     => 'Leve',
            'detalleDanio'   => 'Esquina de la caja hundida',
            'precioVenta'    => '24,50',
            'unidades'       => '5',
            'publicar'       => 'Sí',
        ], $cambios);
    }

    /** Origen B: servicio externo de rastreo (simulado). Anidado, en inglés y con tipos JSON. */
    public static function entradaRastreo(array $cambios = []): array
    {
        return array_replace_recursive([
            'tracking_number'    => 'EC123456789EC',
            'item'               => [
                'title'    => 'Licuadora Oster - caja golpeada',
                'details'  => 'Licuadora de 1.5 L, funciona correctamente',
                'quantity' => 5,
            ],
            'damage'             => ['severity_code' => 1, 'notes' => 'Esquina de la caja hundida'],
            'pricing'            => ['amount' => 24.5, 'currency' => 'USD'],
            'available_for_sale' => true,
            // Campos que el contrato no usa y el traductor descarta
            'received_at'        => '2026-11-11T03:15:00Z',
            'office'             => ['code' => 'MNT', 'name' => 'Agencia Manta'],
        ], $cambios);
    }

    private const CONTRATO_ESPERADO = [
        'name'        => 'Licuadora Oster - caja golpeada',
        'description' => 'Licuadora de 1.5 L, funciona correctamente',
        'price'       => 24.5,
        'stock'       => 5,
        'sku'         => 'EC123456789EC',
        'damageLevel' => 'leve',
        'damageNotes' => 'Esquina de la caja hundida',
        'status'      => 'published',
    ];

    // P1: entrada A válida -> contrato común correcto
    public function test_p1_entrada_web_valida_produce_contrato_comun(): void
    {
        $this->postJson('/api/integracion/web/normalizar', self::entradaWeb())
            ->assertOk()
            ->assertExactJson(['origen' => 'web', 'data' => self::CONTRATO_ESPERADO]);
    }

    // P2: entrada B equivalente -> mismo significado y mismos tipos que P1
    public function test_p2_entrada_rastreo_equivalente_produce_el_mismo_contrato(): void
    {
        $this->postJson('/api/integracion/rastreo/normalizar', self::entradaRastreo())
            ->assertOk()
            ->assertExactJson(['origen' => 'rastreo', 'data' => self::CONTRATO_ESPERADO]);

        // assertSame compara valores Y tipos (24.5 float, 5 int), no solo el texto.
        $normalizador = new NormalizadorProductoDanado();
        $this->assertSame(
            $normalizador->desdeFormularioWeb(self::entradaWeb())->toArray(),
            $normalizador->desdeServicioRastreo(self::entradaRastreo())->toArray(),
        );
    }

    // P3a: monto inválido -> error explícito y no se registra nada
    public function test_p3_monto_ambiguo_devuelve_error_explicito(): void
    {
        $this->postJson('/api/integracion/web/productos', self::entradaWeb(['precioVenta' => '24.50']))
            ->assertUnprocessable()
            ->assertJsonPath('origen', 'web')
            ->assertJsonValidationErrors(['precioVenta']);

        $this->assertDatabaseCount('damaged_products', 0);
    }

    // P3b: campo faltante -> error explícito con la ruta del campo de origen
    public function test_p3_campo_faltante_devuelve_error_explicito(): void
    {
        $entrada = self::entradaRastreo();
        unset($entrada['tracking_number'], $entrada['pricing']['amount']);

        $this->postJson('/api/integracion/rastreo/productos', $entrada)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tracking_number', 'pricing.amount']);

        $this->assertDatabaseCount('damaged_products', 0);
    }

    /** Valores que no se deben convertir en silencio. */
    public static function entradasMalFormadas(): array
    {
        return [
            'web: miles con punto'          => ['web', self::entradaWeb(['precioVenta' => '1.250,00']), 'precioVenta'],
            'web: unidades con decimal'     => ['web', self::entradaWeb(['unidades' => '5.0']), 'unidades'],
            'web: unidades en cero'         => ['web', self::entradaWeb(['unidades' => '0']), 'unidades'],
            'web: grado no reconocido'      => ['web', self::entradaWeb(['gradoDanio' => 'Medio']), 'gradoDanio'],
            'web: publicar ambiguo'         => ['web', self::entradaWeb(['publicar' => 'Tal vez']), 'publicar'],
            'web: guía mal formada'         => ['web', self::entradaWeb(['codigoGuia' => 'EC12345']), 'codigoGuia'],
            'web: nombre muy corto'         => ['web', self::entradaWeb(['nombreProducto' => 'TV']), 'nombreProducto'],
            'rastreo: monto como texto'     => ['rastreo', self::entradaRastreo(['pricing' => ['amount' => '24.50']]), 'pricing.amount'],
            'rastreo: tres decimales'       => ['rastreo', self::entradaRastreo(['pricing' => ['amount' => 24.505]]), 'pricing.amount'],
            'rastreo: otra moneda'          => ['rastreo', self::entradaRastreo(['pricing' => ['currency' => 'EUR']]), 'pricing.currency'],
            'rastreo: severidad 4'          => ['rastreo', self::entradaRastreo(['damage' => ['severity_code' => 4]]), 'damage.severity_code'],
            'rastreo: cantidad texto'       => ['rastreo', self::entradaRastreo(['item' => ['quantity' => '5']]), 'item.quantity'],
            'rastreo: venta como texto'     => ['rastreo', self::entradaRastreo(['available_for_sale' => 'true']), 'available_for_sale'],
            'rastreo: detalle no es texto'  => ['rastreo', self::entradaRastreo(['damage' => ['notes' => 123]]), 'damage.notes'],
        ];
    }

    #[DataProvider('entradasMalFormadas')]
    public function test_p3_valores_mal_formados_se_rechazan(string $origen, array $entrada, string $campo): void
    {
        $this->postJson("/api/integracion/{$origen}/normalizar", $entrada)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$campo]);
    }

    // Integración: el contrato común se guarda en damaged_products y responde como POST /api/products
    public function test_registro_en_damaged_products_y_rechazo_del_mismo_sku_desde_otro_origen(): void
    {
        $this->postJson('/api/integracion/web/productos', self::entradaWeb())
            ->assertCreated()
            ->assertJsonPath('origen', 'web')
            ->assertJsonPath('data.sku', 'EC123456789EC')
            ->assertJsonPath('data.name', 'Licuadora Oster - caja golpeada')
            ->assertJsonPath('data.price', 24.5)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonStructure(['data' => [
                'id', 'sku', 'name', 'description', 'damageLevel', 'damageNotes',
                'price', 'stock', 'status', 'publishedAt', 'createdAt',
            ]]);

        $this->assertDatabaseHas('damaged_products', [
            'sku' => 'EC123456789EC', 'title' => 'Licuadora Oster - caja golpeada',
            'damage_level' => 'leve', 'stock' => 5, 'status' => 'published',
        ]);

        // El mismo paquete reportado por el servicio de rastreo choca con la regla unique del contrato.
        $this->postJson('/api/integracion/rastreo/productos', self::entradaRastreo())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this->assertDatabaseCount('damaged_products', 1);
    }

    // "No" en el formulario y false en el rastreo dejan el producto como borrador
    public function test_producto_no_publicado_queda_como_borrador_en_ambos_origenes(): void
    {
        $normalizador = new NormalizadorProductoDanado();

        $this->assertSame('draft', $normalizador->desdeFormularioWeb(self::entradaWeb(['publicar' => 'no']))->status);
        $this->assertSame('draft', $normalizador->desdeServicioRastreo(self::entradaRastreo(['available_for_sale' => false]))->status);
    }

    public function test_origen_no_soportado_responde_404(): void
    {
        $this->postJson('/api/integracion/excel/normalizar', [])->assertNotFound();
    }
}
