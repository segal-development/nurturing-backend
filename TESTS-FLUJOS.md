# Tests para Sistema de Flujos

Este documento describe los tests creados para el sistema de flujos y cómo ejecutarlos.

## Tests Creados

### 1. Tests Unitarios de Modelos

#### `tests/Unit/Models/FlujoTest.php`
- ✅ 36 tests para el modelo `Flujo`
- **Coverage esperado**: 100% del modelo
- **Casos cubiertos**:
  - Creación con datos mínimos y completos
  - Scopes (activos, porTipo, porOrigen)
  - Método `findOrCreateForProspecto()`
  - Método `generarNombre()`
  - Accessor `flujo_data`
  - Relaciones (tipoProspecto, user, ejecuciones)
  - Casts de datos
  - Casos edge: null values, arrays vacíos

#### `tests/Unit/Models/FlujoEjecucionTest.php`
- ✅ 17 tests para el modelo `FlujoEjecucion`
- **Coverage esperado**: 100% del modelo
- **Casos cubiertos**:
  - Creación y actualización de ejecuciones
  - Almacenamiento de IDs de prospectos (arrays)
  - Scopes (pendientes, enProgreso, deberianHaberComenzado)
  - Configuración compleja (JSON)
  - Manejo de errores
  - Transiciones de estado
  - Relaciones

### 2. Tests Unitarios de Jobs

#### `tests/Unit/Jobs/EnviarEtapaJobTest.php`
- ✅ 17 tests para `EnviarEtapaJob`
- **Coverage esperado**: 90% del job
- **Casos cubiertos**:
  - Creación del job
  - Actualización de estados (executing → completed)
  - Envío de mensajes (mock de EnvioService)
  - Registro en `flujo_jobs`
  - Manejo de errores
  - Encadenamiento de etapas
  - Verificación de condiciones
  - Arrays vacíos de prospectos
  - Configuración (timeout, tries, backoff)

## Ejecutar Tests

### Todos los tests
```bash
php artisan test
```

### Solo tests unitarios
```bash
php artisan test --testsuite=Unit
```

### Tests específicos de flujos
```bash
php artisan test tests/Unit/Models/FlujoTest.php
php artisan test tests/Unit/Models/FlujoEjecucionTest.php
php artisan test tests/Unit/Jobs/EnviarEtapaJobTest.php
```

### Con coverage
```bash
php artisan test --coverage --min=80
```

### Ejecutar un test específico
```bash
php artisan test --filter=puede_crear_un_flujo_con_datos_minimos
```

## Correcciones Necesarias

### 1. Ajustar tests que usan `json_encode` para casts

Algunos tests fallan porque los casts de Laravel ya convierten automáticamente de JSON a array.

**Problema:**
```php
$flujo = Flujo::factory()->create([
    'metadata' => json_encode(['test' => 'value']), // ❌ No funciona
]);
```

**Solución:**
```php
$flujo = Flujo::factory()->create([
    'metadata' => ['test' => 'value'], // ✅ Laravel maneja la conversión
]);
```

### 2. Método `generarNombre()` es protegido

El test que llama directamente a `Flujo::generarNombre()` fallará porque es `protected`.

**Solución**: Usar reflexión o testear indirectamente a través de `findOrCreateForProspecto()`.

## Tests Pendientes por Crear

### Alta Prioridad
1. **Feature Tests**:
   - `tests/Feature/FlujoControllerTest.php`
   - `tests/Feature/FlujoEjecucionControllerTest.php`

2. **Más Jobs**:
   - `tests/Unit/Jobs/VerificarCondicionJobTest.php`
   - `tests/Unit/Jobs/EnviarEmailProspectoJobTest.php`
   - `tests/Unit/Jobs/EnviarSmsProspectoJobTest.php`

3. **Services**:
   - `tests/Unit/Services/EnvioServiceTest.php`

### Mediana Prioridad
4. **Más Modelos**:
   - `tests/Unit/Models/FlujoEjecucionEtapaTest.php`
   - `tests/Unit/Models/FlujoJobTest.php`
   - `tests/Unit/Models/FlujoLogTest.php`

## Estructura de Tests Recomendada

```
tests/
├── Unit/
│   ├── Models/
│   │   ├── FlujoTest.php ✅
│   │   ├── FlujoEjecucionTest.php ✅
│   │   ├── FlujoEjecucionEtapaTest.php (pendiente)
│   │   └── ...
│   ├── Jobs/
│   │   ├── EnviarEtapaJobTest.php ✅
│   │   ├── VerificarCondicionJobTest.php (pendiente)
│   │   └── ...
│   └── Services/
│       └── EnvioServiceTest.php (pendiente)
└── Feature/
    ├── FlujoControllerTest.php (pendiente)
    ├── FlujoEjecucionControllerTest.php (pendiente)
    └── ...
```

## Convenciones de Tests

### Nomenclatura
- Usar snake_case para nombres de tests
- Ser descriptivo: `puede_crear_un_flujo_con_datos_minimos`
- Evitar abreviaciones

### Estructura de Tests (AAA Pattern)
```php
public function test_nombre_descriptivo(): void
{
    // Arrange (Preparar)
    $flujo = Flujo::factory()->create();

    // Act (Actuar)
    $resultado = $flujo->metodo();

    // Assert (Afirmar)
    $this->assertEquals($esperado, $resultado);
}
```

### Mocking
- Mockear dependencias externas (APIs, servicios de terceros)
- Usar `Mockery` para mocks complejos
- Limpiar mocks en `tearDown()`

```php
protected function tearDown(): void
{
    Mockery::close();
    parent::tearDown();
}
```

### Datos de Prueba
- Usar factories siempre que sea posible
- No hardcodear IDs
- Usar `RefreshDatabase` para limpiar la BD entre tests

## Verificar Coverage

```bash
# Coverage general
php artisan test --coverage

# Coverage con reporte HTML
php artisan test --coverage-html=coverage

# Ver reporte
open coverage/index.html
```

## Objetivo de Coverage

- **Core Functions (Modelos, Jobs)**: 100%
- **Controllers**: 80%
- **Resto del código**: 80%
- **Infraestructura (config, migrations)**: 0%

## Próximos Pasos

1. ✅ Corregir tests que fallan
2. ⏳ Crear tests de features para controllers
3. ⏳ Crear tests para servicios
4. ⏳ Alcanzar 80% de coverage mínimo
5. ⏳ Configurar CI/CD para ejecutar tests automáticamente

## Notas

- Los tests están diseñados para PHPUnit 11
- Usar atributos PHP en lugar de anotaciones doc-comment
- Mantener tests rápidos (< 5 segundos total)
- Evitar tests que dependan de bases de datos externas
