# Práctica 2.3: patrones de traducción y normalización (IS-803)

## Escenario

En el proyecto de aula, el backend crea productos dañados con `POST /api/products`. Ese es el **contrato común**, definido en `ProductRequest` y guardado en la tabla `damaged_products`. El mismo producto puede llegar desde dos componentes:

| | Origen A: formulario web de agencia (React) | Origen B: servicio de rastreo (externo, simulado) |
| --- | --- | --- |
| Nombres | camelCase en español | snake_case en inglés |
| Estructura | plana | anidada (`item`, `damage`, `pricing`) |
| Tipos | todo texto | números, enteros y booleanos JSON |
| Precio | `"24,50"` (coma decimal) | `24.5` + `currency` |
| Nivel de daño | `"Leve"` | `severity_code: 1` |
| Publicar | `"Sí"` / `"No"` | `available_for_sale: true` / `false` |
| Datos extra | — | `received_at`, `office` (no están en el contrato) |

Los dos se traducen al contrato de `POST /api/products`, **sin modificarlo**:

```json
{
  "name": "Licuadora Oster - caja golpeada",
  "description": "Licuadora de 1.5 L, funciona correctamente",
  "price": 24.5,
  "stock": 5,
  "sku": "EC123456789EC",
  "damageLevel": "leve",
  "damageNotes": "Esquina de la caja hundida",
  "status": "published"
}
```

## Tabla de mapeo

| Origen A | Origen B | Contrato común | Regla |
| --- | --- | --- | --- |
| `nombreProducto` | `item.title` | `name` | Renombrar/aplanar, recortar y colapsar espacios; de 3 a 150 caracteres |
| `descripcion` (opcional) | `item.details` (opcional) | `description` | Si no viene o está vacío queda `null`; si viene debe ser texto |
| `precioVenta`: `"24,50"` | `pricing.amount`: `24.5` | `price` (number) | A: regex `^\d{1,6}(,\d{1,2})?$` y cambiar la coma por punto. B: número JSON con máximo 2 decimales. Siempre > 0 |
| (implícito USD) | `pricing.currency` | (precio en USD) | Si la moneda no es USD se rechaza; no se convierte |
| `unidades`: `"5"` | `item.quantity`: `5` | `stock` (integer) | A: solo dígitos. B: entero JSON. Rango de 1 a 999 |
| `codigoGuia`: `" ec123…ec "` | `tracking_number` | `sku` | Recortar, pasar a mayúsculas y validar el formato S10 `AA999999999AA` |
| `gradoDanio`: `"Leve"` | `damage.severity_code`: `1` | `damageLevel`: `"leve"` | A: minúsculas y sin tildes. B: tabla 1→leve, 2→moderado, 3→severo |
| `detalleDanio` (opcional) | `damage.notes` (opcional) | `damageNotes` | Igual que `description` |
| `publicar`: `"Sí"` / `"No"` | `available_for_sale`: `true` / `false` | `status`: `published` / `draft` | A: solo «sí» o «no». B: solo booleano JSON |
| — | `received_at`, `office` | — | No forman parte del contrato y se descartan |

## Estructura del módulo

| Archivo | Rol |
| --- | --- |
| `app/Services/Normalizacion/NormalizadorProductoDanado.php` | **Traductor**: `desdeFormularioWeb()`, `desdeServicioRastreo()` y la validación final con las reglas de `ProductRequest` |
| `app/DTOs/ProductoDanadoNormalizado.php` | Contrato común (DTO inmutable), con `toArray()` y `attributesForModel()` |
| `app/Http/Requests/ProductRequest.php` | Contrato de `POST /api/products`, copiado **sin cambios** del backend del proyecto |
| `app/Models/DamagedProduct.php`, `app/Http/Resources/ProductResource.php` | Modelo y respuesta del backend del proyecto |
| `database/migrations/2026_10_05_000003_create_damaged_products_table.php` | Tabla `damaged_products` del proyecto. Los CHECK de PostgreSQL solo se crean en pgsql |
| `app/Exceptions/DatosNoNormalizablesException.php` | Error 422 con cada campo de origen que falló |
| `app/Services/RecepcionProductoService.php` | Guarda el contrato común en `damaged_products` |
| `app/Http/Controllers/Api/IntegracionProductoController.php` | Recibe el JSON y lo pasa al traductor. No transforma nada por sí mismo |
| `tests/Feature/NormalizacionProductoTest.php` | Pruebas P1, P2, P3 y 14 casos de entradas mal formadas |

## Endpoints

| Método | Ruta | Resultado |
| --- | --- | --- |
| POST | `/api/integracion/{web\|rastreo}/normalizar` | 200 con el contrato común. No guarda nada |
| POST | `/api/integracion/{web\|rastreo}/productos` | 201: traduce, guarda en `damaged_products` y responde igual que `POST /api/products` |

| Código | Caso |
| --- | --- |
| 200 / 201 | Traducción correcta / producto registrado |
| 404 | Origen no soportado (por ejemplo `/integracion/excel/...`) |
| 422 | La entrada no se pudo normalizar o no cumple el contrato, por ejemplo con un `sku` ya registrado. `errors` indica cada campo |

## Ejecución

Requisitos: PHP 8.2+, Composer y Postman. Las pruebas usan SQLite; en el proyecto real la tabla vive en PostgreSQL (L4).

```bash
composer install            # solo si falta la carpeta vendor
cp .env.example .env        # solo si falta .env
php artisan key:generate    # solo si se creó .env
php artisan migrate:fresh
php artisan serve
```

Pruebas automáticas:

```bash
php artisan test --filter=NormalizacionProductoTest   # 21 pruebas
```

Postman: importa `Practica_2_3.postman_collection.json` y ejecuta en orden de P1 a P5. Todas las peticiones llevan `Accept: application/json`.

| Caso | Petición | Resultado esperado |
| --- | --- | --- |
| P1 | Origen A web válido | 200 con el contrato común |
| P2 | Origen B rastreo equivalente | 200 con **el mismo** `data` que P1 |
| P3a | Origen A con `precioVenta: "24.50"` | 422 con `errors.precioVenta` |
| P3b | Origen B sin `tracking_number` ni `pricing.amount` | 422 con los dos errores |
| P3c | Origen B con moneda EUR, severidad 4 y `available_for_sale: "true"` | 422 con tres errores |
| P4 | Registrar desde web | 201 con la respuesta de `ProductResource` |
| P5 | El mismo producto desde rastreo | 422 con `errors.sku`, porque no se duplica |

Si P4 devuelve 422 por el `sku`, es porque ya lo ejecutaste antes. Reinicia los datos con `php artisan migrate:fresh`.
