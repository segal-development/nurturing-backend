<?php

namespace Database\Seeders;

use App\Models\ExternalApiSource;
use App\Models\Importacion;
use App\Models\Lote;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder para crear datos fake de prueba para Grupo Deudas.
 *
 * Crea prospectos fake para:
 * - Contratos Nuevos (grupo_deuda_contratos)
 * - Cuotas Vencidas (grupo_deuda_cuotas_vencidas)
 *
 * Usa emails y teléfonos reales para poder probar el envío de emails/SMS.
 *
 * Ejecutar con: php artisan db:seed --class=GrupoDeudaFakeDataSeeder
 */
class GrupoDeudaFakeDataSeeder extends Seeder
{
    /**
     * Datos de contacto reales para pruebas.
     */
    private const TEST_CONTACTS = [
        [
            'nombre' => 'Marcelo Toro (Prueba)',
            'email' => 'mtoro@segal.cl',
            'telefono' => '+56958531798',
            'rut' => '15445854-9',
        ],
        [
            'nombre' => 'Juan Figueroa (Prueba)',
            'email' => 'jfigueroa@itds.cl',
            'telefono' => '+56984646029',
            'rut' => '16884340-2',
        ],
        [
            'nombre' => 'Carlos Salinas (Prueba)',
            'email' => 'csalinas@segal.cl',
            'telefono' => '+56958531798', // Reutilizamos número
            'rut' => null,
        ],
    ];

    /**
     * Nombres fake chilenos para completar los prospectos.
     */
    private const FAKE_NAMES = [
        'María González Pérez',
        'José Muñoz Silva',
        'Carmen Rodríguez López',
        'Luis Hernández Díaz',
        'Ana Martínez Soto',
        'Pedro Sánchez Vargas',
        'Rosa Flores Morales',
        'Juan Torres Reyes',
        'Patricia Ramírez Castro',
        'Carlos Vega Núñez',
    ];

    public function run(): void
    {
        $this->command->info('');
        $this->command->info('==============================================');
        $this->command->info('  SEEDER: Datos Fake para Grupo Deudas');
        $this->command->info('==============================================');
        $this->command->info('');

        // Obtener usuario del sistema (o el primero disponible)
        $user = User::first();
        if (!$user) {
            $this->command->error('No hay usuarios en la base de datos. Ejecuta primero los seeders base.');
            return;
        }

        // Obtener tipos de prospecto
        $tiposProspecto = TipoProspecto::orderBy('monto_min')->get();
        if ($tiposProspecto->isEmpty()) {
            $this->command->error('No hay tipos de prospecto. Ejecuta: php artisan db:seed --class=TipoProspectoSeeder');
            return;
        }

        DB::transaction(function () use ($user, $tiposProspecto) {
            $this->seedContratosNuevos($user, $tiposProspecto);
            $this->seedCuotasVencidas($user, $tiposProspecto);
            $this->seedCuotasPorVencer($user, $tiposProspecto);
            $this->seedClientesPorFechaIngreso($user, $tiposProspecto);
        });

        $this->command->info('');
        $this->command->info('✅ Datos fake creados exitosamente!');
        $this->command->info('');
        $this->command->info('Ahora puedes:');
        $this->command->info('  1. Crear un flujo seleccionando uno de los orígenes de Grupo Deudas');
        $this->command->info('  2. Seleccionar el lote correspondiente:');
        $this->command->info('     - CONTRATOS_NUEVOS_TEST');
        $this->command->info('     - CUOTAS_VENCIDAS_TEST');
        $this->command->info('     - CUOTAS_POR_VENCER_TEST');
        $this->command->info('     - CLIENTES_INGRESO_TEST');
        $this->command->info('  3. Ejecutar el flujo para probar emails/SMS');
        $this->command->info('');
    }

    /**
     * Crea prospectos fake para Contratos Nuevos.
     */
    private function seedContratosNuevos(User $user, $tiposProspecto): void
    {
        $this->command->info('📋 Creando datos para CONTRATOS NUEVOS...');

        // Buscar o crear el ExternalApiSource
        $source = ExternalApiSource::where('name', 'grupo_deuda_contratos')->first();
        if (!$source) {
            $this->command->warn('  ⚠️  No existe grupo_deuda_contratos. Ejecuta GrupoDeudaApiSourceSeeder primero.');
            $this->command->info('  Creando source temporal...');
            $source = ExternalApiSource::create([
                'name' => 'grupo_deuda_contratos',
                'display_name' => 'Grupo Deudas - Contratos Nuevos (Test)',
                'endpoint_url' => 'https://example.com/test',
                'auth_type' => 'none',
                'is_active' => true,
            ]);
        }

        // Crear Lote
        $lote = Lote::create([
            'nombre' => 'CONTRATOS_NUEVOS_TEST',
            'user_id' => $user->id,
            'external_api_source_id' => $source->id,
            'estado' => 'completado',
            'metadata' => [
                'tipo' => 'fake_data',
                'descripcion' => 'Datos de prueba para testing de flujos',
                'created_by' => 'GrupoDeudaFakeDataSeeder',
            ],
        ]);

        $this->command->info("  ✓ Lote creado: {$lote->nombre} (ID: {$lote->id})");

        // Crear Importación - usar display_name del source para consistencia con API real
        $importacion = Importacion::create([
            'lote_id' => $lote->id,
            'nombre_archivo' => 'fake_contratos_nuevos.json',
            'ruta_archivo' => '/fake/contratos_nuevos.json',
            'origen' => $source->display_name,
            'user_id' => $user->id,
            'estado' => 'completado',
            'fecha_importacion' => now(),
            'external_api_source_id' => $source->id,
            'metadata' => [
                'tipo' => 'fake_data',
                'endpoint' => 'contratos_nuevos',
            ],
        ]);

        // Crear prospectos con contactos reales
        $creados = 0;
        foreach (self::TEST_CONTACTS as $index => $contact) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);

            // Verificar si ya existe el email
            $existente = Prospecto::where('email', $contact['email'])->first();
            if ($existente) {
                $this->command->warn("  ⚠️  Email {$contact['email']} ya existe, actualizando...");
                $existente->update([
                    'importacion_id' => $importacion->id,
                    'nombre' => $contact['nombre'],
                    'telefono' => $contact['telefono'],
                    'monto_deuda' => $montoDeuda,
                    'tipo_prospecto_id' => $tipoProspecto->id,
                    'estado' => 'activo',
                    'metadata' => $this->buildContratosMetadata($index + 1, $contact, $montoDeuda),
                ]);
                $creados++;
                continue;
            }

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $contact['nombre'],
                'email' => $contact['email'],
                'telefono' => $contact['telefono'],
                'rut' => $contact['rut'] ? str_replace('.', '', $contact['rut']) : null,
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildContratosMetadata($index + 1, $contact, $montoDeuda),
            ]);
            $creados++;
        }

        // Agregar algunos prospectos fake adicionales (sin contacto real)
        for ($i = 0; $i < 5; $i++) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);
            $fakeName = self::FAKE_NAMES[$i];
            $fakeEmail = 'fake.contrato.' . ($i + 1) . '@test.local';
            $fakePhone = '+5691234' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);

            // Skip si ya existe
            if (Prospecto::where('email', $fakeEmail)->exists()) {
                continue;
            }

            $fakeContact = [
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => $fakePhone,
                'rut' => $this->generateFakeRut(),
            ];

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => $fakePhone,
                'rut' => str_replace('-', '', $fakeContact['rut']),
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildContratosMetadata($i + 100, $fakeContact, $montoDeuda),
            ]);
            $creados++;
        }

        // Actualizar contadores del lote e importación
        $importacion->update([
            'total_registros' => $creados,
            'registros_exitosos' => $creados,
        ]);
        $lote->update([
            'total_registros' => $creados,
            'registros_exitosos' => $creados,
        ]);

        $this->command->info("  ✓ Creados {$creados} prospectos para Contratos Nuevos");
    }

    /**
     * Crea prospectos fake para Cuotas Vencidas.
     */
    private function seedCuotasVencidas(User $user, $tiposProspecto): void
    {
        $this->command->info('');
        $this->command->info('📋 Creando datos para CUOTAS VENCIDAS...');

        // Buscar o crear el ExternalApiSource
        $source = ExternalApiSource::where('name', 'grupo_deuda_cuotas_vencidas')->first();
        if (!$source) {
            $this->command->warn('  ⚠️  No existe grupo_deuda_cuotas_vencidas. Creando source temporal...');
            $source = ExternalApiSource::create([
                'name' => 'grupo_deuda_cuotas_vencidas',
                'display_name' => 'Grupo Deudas - Cuotas Vencidas (Test)',
                'endpoint_url' => 'https://example.com/test',
                'auth_type' => 'none',
                'is_active' => true,
            ]);
        }

        // Crear Lote
        $lote = Lote::create([
            'nombre' => 'CUOTAS_VENCIDAS_TEST',
            'user_id' => $user->id,
            'external_api_source_id' => $source->id,
            'estado' => 'completado',
            'metadata' => [
                'tipo' => 'fake_data',
                'descripcion' => 'Datos de prueba para testing de flujos - Cuotas Vencidas',
                'created_by' => 'GrupoDeudaFakeDataSeeder',
            ],
        ]);

        $this->command->info("  ✓ Lote creado: {$lote->nombre} (ID: {$lote->id})");

        // Crear Importación - usar display_name del source para consistencia con API real
        $importacion = Importacion::create([
            'lote_id' => $lote->id,
            'nombre_archivo' => 'fake_cuotas_vencidas.json',
            'ruta_archivo' => '/fake/cuotas_vencidas.json',
            'origen' => $source->display_name,
            'user_id' => $user->id,
            'estado' => 'completado',
            'fecha_importacion' => now(),
            'external_api_source_id' => $source->id,
            'metadata' => [
                'tipo' => 'fake_data',
                'endpoint' => 'cuotas_vencidas',
            ],
        ]);

        // Crear prospectos con contactos reales (solo los que tienen RUT)
        $creados = 0;
        foreach (self::TEST_CONTACTS as $index => $contact) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);
            
            // Email único para cuotas vencidas
            $email = str_replace('@', '.cuotas@', $contact['email']);
            $nombre = str_replace('(Prueba)', '(Cuotas Vencidas)', $contact['nombre']);

            // Verificar si ya existe
            if (Prospecto::where('email', $email)->exists()) {
                $this->command->warn("  ⚠️  Email {$email} ya existe, saltando...");
                continue;
            }

            $contactCuotas = [
                'nombre' => $nombre,
                'email' => $email,
                'telefono' => $contact['telefono'],
                'rut' => $contact['rut'],
            ];

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $nombre,
                'email' => $email,
                'telefono' => $contact['telefono'],
                'rut' => $contact['rut'] ? str_replace('.', '', $contact['rut']) : null,
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildCuotasVencidasMetadata($index + 1, $contactCuotas, $montoDeuda),
            ]);
            $creados++;
        }

        // Agregar algunos prospectos fake adicionales
        for ($i = 0; $i < 5; $i++) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);
            $fakeName = self::FAKE_NAMES[$i + 5] ?? self::FAKE_NAMES[$i];
            $fakeEmail = 'fake.cuotas.' . ($i + 1) . '@test.local';
            $fakePhone = '+5698765' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);

            if (Prospecto::where('email', $fakeEmail)->exists()) {
                continue;
            }

            $fakeContact = [
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => $fakePhone,
                'rut' => $this->generateFakeRut(),
            ];

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => $fakePhone,
                'rut' => str_replace('-', '', $fakeContact['rut']),
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildCuotasVencidasMetadata($i + 100, $fakeContact, $montoDeuda),
            ]);
            $creados++;
        }

        // Actualizar contadores
        $importacion->update([
            'total_registros' => $creados,
            'registros_exitosos' => $creados,
        ]);
        $lote->update([
            'total_registros' => $creados,
            'registros_exitosos' => $creados,
        ]);

        $this->command->info("  ✓ Creados {$creados} prospectos para Cuotas Vencidas");
    }

    /**
     * Crea prospectos fake para Cuotas Por Vencer.
     */
    private function seedCuotasPorVencer(User $user, $tiposProspecto): void
    {
        $this->command->info('');
        $this->command->info('📋 Creando datos para CUOTAS POR VENCER...');

        // Buscar o crear el ExternalApiSource
        $source = ExternalApiSource::where('name', 'grupo_deuda_cuotas_por_vencer')->first();
        if (!$source) {
            $this->command->warn('  ⚠️  No existe grupo_deuda_cuotas_por_vencer. Creando source temporal...');
            $source = ExternalApiSource::create([
                'name' => 'grupo_deuda_cuotas_por_vencer',
                'display_name' => 'Grupo Deudas - Cuotas Por Vencer (Test)',
                'endpoint_url' => 'https://example.com/test',
                'auth_type' => 'none',
                'is_active' => true,
            ]);
        }

        // Crear Lote
        $lote = Lote::create([
            'nombre' => 'CUOTAS_POR_VENCER_TEST',
            'user_id' => $user->id,
            'external_api_source_id' => $source->id,
            'estado' => 'completado',
            'metadata' => [
                'tipo' => 'fake_data',
                'descripcion' => 'Datos de prueba para testing de flujos - Cuotas Por Vencer',
                'created_by' => 'GrupoDeudaFakeDataSeeder',
            ],
        ]);

        $this->command->info("  ✓ Lote creado: {$lote->nombre} (ID: {$lote->id})");

        // Crear Importación
        $importacion = Importacion::create([
            'lote_id' => $lote->id,
            'nombre_archivo' => 'fake_cuotas_por_vencer.json',
            'ruta_archivo' => '/fake/cuotas_por_vencer.json',
            'origen' => $source->display_name,
            'user_id' => $user->id,
            'estado' => 'completado',
            'fecha_importacion' => now(),
            'external_api_source_id' => $source->id,
            'metadata' => [
                'tipo' => 'fake_data',
                'endpoint' => 'cuotas_por_vencer',
            ],
        ]);

        // Crear prospectos con contactos reales
        $creados = 0;
        foreach (self::TEST_CONTACTS as $index => $contact) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);
            
            // Email único para cuotas por vencer
            $email = str_replace('@', '.porvencer@', $contact['email']);
            $nombre = str_replace('(Prueba)', '(Por Vencer)', $contact['nombre']);

            // Verificar si ya existe
            if (Prospecto::where('email', $email)->exists()) {
                $this->command->warn("  ⚠️  Email {$email} ya existe, saltando...");
                continue;
            }

            $contactPorVencer = [
                'nombre' => $nombre,
                'email' => $email,
                'telefono' => $contact['telefono'],
                'rut' => $contact['rut'],
            ];

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $nombre,
                'email' => $email,
                'telefono' => $contact['telefono'],
                'rut' => $contact['rut'] ? str_replace('.', '', $contact['rut']) : null,
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildCuotasPorVencerMetadata($index + 1, $contactPorVencer, $montoDeuda),
            ]);
            $creados++;
        }

        // Agregar algunos prospectos fake adicionales
        for ($i = 0; $i < 5; $i++) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);
            $fakeName = self::FAKE_NAMES[$i];
            $fakeEmail = 'fake.porvencer.' . ($i + 1) . '@test.local';
            $fakePhone = '+5697654' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);

            if (Prospecto::where('email', $fakeEmail)->exists()) {
                continue;
            }

            $fakeContact = [
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => $fakePhone,
                'rut' => $this->generateFakeRut(),
            ];

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => $fakePhone,
                'rut' => str_replace('-', '', $fakeContact['rut']),
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildCuotasPorVencerMetadata($i + 100, $fakeContact, $montoDeuda),
            ]);
            $creados++;
        }

        // Actualizar contadores
        $importacion->update([
            'total_registros' => $creados,
            'registros_exitosos' => $creados,
        ]);
        $lote->update([
            'total_registros' => $creados,
            'registros_exitosos' => $creados,
        ]);

        $this->command->info("  ✓ Creados {$creados} prospectos para Cuotas Por Vencer");
    }

    /**
     * Crea prospectos fake para Clientes Por Fecha Ingreso.
     */
    private function seedClientesPorFechaIngreso(User $user, $tiposProspecto): void
    {
        $this->command->info('');
        $this->command->info('📋 Creando datos para CLIENTES POR FECHA INGRESO...');

        // Buscar o crear el ExternalApiSource
        $source = ExternalApiSource::where('name', 'grupo_deuda_clientes_ingreso')->first();
        if (!$source) {
            $this->command->warn('  ⚠️  No existe grupo_deuda_clientes_ingreso. Creando source temporal...');
            $source = ExternalApiSource::create([
                'name' => 'grupo_deuda_clientes_ingreso',
                'display_name' => 'Grupo Deudas - Clientes Ingreso (Test)',
                'endpoint_url' => 'https://example.com/test',
                'auth_type' => 'none',
                'is_active' => true,
            ]);
        }

        // Crear Lote
        $lote = Lote::create([
            'nombre' => 'CLIENTES_INGRESO_TEST',
            'user_id' => $user->id,
            'external_api_source_id' => $source->id,
            'estado' => 'completado',
            'metadata' => [
                'tipo' => 'fake_data',
                'descripcion' => 'Datos de prueba para testing de flujos - Clientes Ingreso',
                'created_by' => 'GrupoDeudaFakeDataSeeder',
            ],
        ]);

        $this->command->info("  ✓ Lote creado: {$lote->nombre} (ID: {$lote->id})");

        // Crear Importación
        $importacion = Importacion::create([
            'lote_id' => $lote->id,
            'nombre_archivo' => 'fake_clientes_ingreso.json',
            'ruta_archivo' => '/fake/clientes_ingreso.json',
            'origen' => $source->display_name,
            'user_id' => $user->id,
            'estado' => 'completado',
            'fecha_importacion' => now(),
            'external_api_source_id' => $source->id,
            'metadata' => [
                'tipo' => 'fake_data',
                'endpoint' => 'clientes_ingreso',
            ],
        ]);

        // Crear prospectos con contactos reales
        $creados = 0;
        foreach (self::TEST_CONTACTS as $index => $contact) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);
            
            // Email único para clientes ingreso
            $email = str_replace('@', '.ingreso@', $contact['email']);
            $nombre = str_replace('(Prueba)', '(Ingreso)', $contact['nombre']);

            // Verificar si ya existe
            if (Prospecto::where('email', $email)->exists()) {
                $this->command->warn("  ⚠️  Email {$email} ya existe, saltando...");
                continue;
            }

            $contactIngreso = [
                'nombre' => $nombre,
                'email' => $email,
                'telefono' => $contact['telefono'],
                'rut' => $contact['rut'],
            ];

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $nombre,
                'email' => $email,
                'telefono' => $contact['telefono'],
                'rut' => $contact['rut'] ? str_replace('.', '', $contact['rut']) : null,
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildClientesIngresoMetadata($index + 1, $contactIngreso, $montoDeuda),
            ]);
            $creados++;
        }

        // Agregar algunos prospectos fake adicionales
        for ($i = 0; $i < 5; $i++) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);
            $fakeName = self::FAKE_NAMES[$i];
            $fakeEmail = 'fake.ingreso.' . ($i + 1) . '@test.local';
            $fakePhone = '+5696543' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);

            if (Prospecto::where('email', $fakeEmail)->exists()) {
                continue;
            }

            $fakeContact = [
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => $fakePhone,
                'rut' => $this->generateFakeRut(),
            ];

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => $fakePhone,
                'rut' => str_replace('-', '', $fakeContact['rut']),
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildClientesIngresoMetadata($i + 100, $fakeContact, $montoDeuda),
            ]);
            $creados++;
        }

        // Actualizar contadores
        $importacion->update([
            'total_registros' => $creados,
            'registros_exitosos' => $creados,
        ]);
        $lote->update([
            'total_registros' => $creados,
            'registros_exitosos' => $creados,
        ]);

        $this->command->info("  ✓ Creados {$creados} prospectos para Clientes Ingreso");
    }

    /**
     * Genera un monto de deuda aleatorio.
     */
    private function getRandomMontoDeuda(): int
    {
        $rangos = [
            [100000, 500000],      // Deuda baja
            [700000, 1200000],     // Deuda media
            [1500000, 5000000],    // Deuda alta
        ];

        $rango = $rangos[array_rand($rangos)];
        return rand($rango[0], $rango[1]);
    }

    /**
     * Obtiene el tipo de prospecto según el monto de deuda.
     */
    private function getTipoProspectoPorMonto($tiposProspecto, int $monto): TipoProspecto
    {
        foreach ($tiposProspecto as $tipo) {
            $min = $tipo->monto_min ?? 0;
            $max = $tipo->monto_max ?? PHP_INT_MAX;

            if ($monto >= $min && $monto <= $max) {
                return $tipo;
            }
        }

        return $tiposProspecto->first();
    }

    /**
     * Construye metadata para Contratos Nuevos.
     * Simula exactamente la estructura de la API /ContratosNuevos
     */
    private function buildContratosMetadata(int $contratoId, array $contact, int $montoDeuda): array
    {
        $vendedores = [
            ['Id' => '93', 'Nombre' => 'Carla Lavin', 'Email' => 'clavin@segal.cl'],
            ['Id' => '261', 'Nombre' => 'Michael Walter', 'Email' => 'mwalter@segal.cl'],
            ['Id' => '142', 'Nombre' => 'Andrea Muñoz', 'Email' => 'amunoz@segal.cl'],
        ];
        $vendedor = $vendedores[array_rand($vendedores)];
        
        $cuotas = rand(12, 48);
        $fechaInicio = now()->format('Y-m-d');
        $fechaTermino = now()->addMonths($cuotas)->format('Y-m-d');

        return [
            // Campos del sistema
            'source' => 'grupo_deuda',
            'endpoint' => 'contratos_nuevos',
            'synced_at' => now()->toISOString(),
            'is_fake_data' => true,
            
            // Estructura exacta de la API /ContratosNuevos
            'Id' => (string) (1080057000 + $contratoId),
            'Cliente' => $contact['nombre'] ?? 'Cliente Prueba',
            'Rut' => $contact['rut'] ?? $this->generateFakeRut(),
            'Email' => $contact['email'] ?? 'test@example.com',
            'Telefono' => ltrim($contact['telefono'] ?? '+56912345678', '+56'),
            'Monto' => (string) $montoDeuda,
            'Cuotas' => (string) $cuotas,
            'Vigencia' => [
                'Inicio' => $fechaInicio,
                'Termino' => $fechaTermino,
            ],
            'Vendedor' => $vendedor,
        ];
    }

    /**
     * Construye metadata para Cuotas Vencidas.
     * Simula exactamente la estructura de la API /CuotasVencidas
     */
    private function buildCuotasVencidasMetadata(int $clienteId, array $contact, int $montoDeuda): array
    {
        // Abogados disponibles
        $abogados = [
            ['Id' => '216', 'Nombre' => 'Bryan', 'Apellido_Paterno' => 'Morales', 'Apellido_Materno' => 'Aedo', 'Email' => 'bmorales@segal.cl', 'Telefono' => '931954203'],
            ['Id' => '85', 'Nombre' => 'Rodrigo', 'Apellido_Paterno' => 'Campos', 'Apellido_Materno' => 'Espinoza', 'Email' => 'rcampos@segal.cl', 'Telefono' => '933919240'],
            ['Id' => '142', 'Nombre' => 'Carolina', 'Apellido_Paterno' => 'Pérez', 'Apellido_Materno' => 'Silva', 'Email' => 'cperez@segal.cl', 'Telefono' => '932456789'],
        ];
        $abogado = $abogados[array_rand($abogados)];

        // Generar cuotas vencidas (1 a 3 cuotas)
        $numCuotas = rand(1, 3);
        $cuotas = [];
        $contratoBase = 1000029000 + $clienteId;
        
        for ($i = 0; $i < $numCuotas; $i++) {
            $cuotas[] = [
                'Contrato' => (string) $contratoBase,
                'Cuota' => (string) rand(1, 24),
                'Vencimiento' => now()->subDays(rand(1, 60))->format('Y-m-d'),
                'Monto' => (string) rand(50000, 200000),
                'Abono' => '0',
                'Estado' => 'MOROSO',
            ];
        }

        // Separar nombre en partes
        $nombreCompleto = $contact['nombre'] ?? 'Cliente Prueba';
        $partes = explode(' ', $nombreCompleto);
        $nombre = $partes[0] ?? 'Cliente';
        $apellidoPaterno = $partes[1] ?? 'Apellido';
        $apellidoMaterno = $partes[2] ?? '';

        return [
            // Campos del sistema
            'source' => 'grupo_deuda',
            'endpoint' => 'cuotas_vencidas',
            'synced_at' => now()->toISOString(),
            'is_fake_data' => true,
            
            // Estructura exacta de la API /CuotasVencidas
            'Id' => (string) (6000 + $clienteId),
            'Nombre' => $nombre,
            'Apellido_Paterno' => $apellidoPaterno,
            'Apellido_Materno' => $apellidoMaterno,
            'Email' => $contact['email'] ?? 'test@example.com',
            'Telefono' => ltrim($contact['telefono'] ?? '+56912345678', '+56'),
            'Abogado' => $abogado,
            'Cuotas' => $cuotas,
        ];
    }

    /**
     * Construye metadata para Cuotas Por Vencer.
     * Simula exactamente la estructura de la API /CuotasPorVencer
     */
    private function buildCuotasPorVencerMetadata(int $clienteId, array $contact, int $montoDeuda): array
    {
        // Abogados disponibles (algunos pueden no tener datos completos)
        $abogados = [
            ['Id' => '202', 'Nombre' => 'Eduardo', 'Apellido_Paterno' => 'Venegas', 'Apellido_Materno' => 'Prado', 'Email' => 'eduardo.venegas@segal.cl', 'Telefono' => '931920188'],
            ['Id' => '85', 'Nombre' => 'Rodrigo', 'Apellido_Paterno' => 'Campos', 'Apellido_Materno' => 'Espinoza', 'Email' => 'rcampos@segal.cl', 'Telefono' => '933919240'],
            ['Id' => '0'], // Algunos clientes no tienen abogado asignado
        ];
        $abogado = $abogados[array_rand($abogados)];

        // Generar cuotas por vencer (1 a 2 cuotas en los próximos días)
        $numCuotas = rand(1, 2);
        $cuotas = [];
        $contratoBase = 1000051000 + $clienteId;
        
        for ($i = 0; $i < $numCuotas; $i++) {
            $cuotas[] = [
                'Contrato' => (string) ($contratoBase + ($i * 1000)),
                'Cuota' => (string) rand(1, 24),
                'Vencimiento' => now()->addDays(rand(1, 14))->format('Y-m-d'),
                'Monto' => (string) rand(45000, 150000),
                'Abono' => '0',
                'Estado' => 'VIGENTE',
            ];
        }

        // Separar nombre en partes
        $nombreCompleto = $contact['nombre'] ?? 'Cliente Prueba';
        $partes = explode(' ', $nombreCompleto);
        $nombre = $partes[0] ?? 'Cliente';
        $apellidoPaterno = $partes[1] ?? 'Apellido';
        $apellidoMaterno = $partes[2] ?? '';

        return [
            // Campos del sistema
            'source' => 'grupo_deuda',
            'endpoint' => 'cuotas_por_vencer',
            'synced_at' => now()->toISOString(),
            'is_fake_data' => true,
            
            // Estructura exacta de la API /CuotasPorVencer
            'Id' => (string) (800 + $clienteId),
            'Nombre' => $nombre,
            'Apellido_Paterno' => $apellidoPaterno,
            'Apellido_Materno' => $apellidoMaterno,
            'Email' => $contact['email'] ?? 'test@example.com',
            'Telefono' => ltrim($contact['telefono'] ?? '+56912345678', '+56'),
            'Abogado' => $abogado,
            'Cuotas' => $cuotas,
        ];
    }

    /**
     * Construye metadata para Clientes Por Fecha Ingreso.
     * Simula exactamente la estructura de la API /ClientesPorFechaIngreso
     */
    private function buildClientesIngresoMetadata(int $clienteId, array $contact, int $montoDeuda): array
    {
        // Abogados disponibles
        $abogados = [
            ['Id' => '169', 'Nombre' => 'Pablo', 'Apellido_Paterno' => 'Acevedo', 'Apellido_Materno' => 'Lopez', 'Email' => 'pacevedo@segal.cl', 'Telefono' => '957463195'],
            ['Id' => '202', 'Nombre' => 'Eduardo', 'Apellido_Paterno' => 'Venegas', 'Apellido_Materno' => 'Prado', 'Email' => 'eduardo.venegas@segal.cl', 'Telefono' => '931920188'],
            ['Id' => '85', 'Nombre' => 'Rodrigo', 'Apellido_Paterno' => 'Campos', 'Apellido_Materno' => 'Espinoza', 'Email' => 'rcampos@segal.cl', 'Telefono' => '933919240'],
        ];
        $abogado = $abogados[array_rand($abogados)];

        // Generar cuotas (pueden tener múltiples contratos)
        $numContratos = rand(1, 2);
        $cuotas = [];
        
        for ($i = 0; $i < $numContratos; $i++) {
            $cuotas[] = [
                'Contrato' => (string) (1000056000 + $clienteId + ($i * 11000)),
                'Cuota' => (string) rand(1, 15),
                'Vencimiento' => now()->addDays(rand(15, 45))->format('Y-m-d'),
                'Monto' => (string) rand(50000, 200000),
                'Abono' => '0',
                'Estado' => 'VIGENTE',
            ];
        }

        // Separar nombre en partes
        $nombreCompleto = $contact['nombre'] ?? 'Cliente Prueba';
        $partes = explode(' ', $nombreCompleto);
        $nombre = $partes[0] ?? 'Cliente';
        $apellidoPaterno = $partes[1] ?? 'Apellido';
        $apellidoMaterno = $partes[2] ?? '';

        return [
            // Campos del sistema
            'source' => 'grupo_deuda',
            'endpoint' => 'clientes_ingreso',
            'synced_at' => now()->toISOString(),
            'is_fake_data' => true,
            
            // Estructura exacta de la API /ClientesPorFechaIngreso
            'Id' => (string) (706000 + $clienteId),
            'Nombre' => $nombre,
            'Apellido_Paterno' => $apellidoPaterno,
            'Apellido_Materno' => $apellidoMaterno,
            'Email' => $contact['email'] ?? 'test@example.com',
            'Telefono' => ltrim($contact['telefono'] ?? '+56912345678', '+56'),
            'Abogado' => $abogado,
            'Cuotas' => $cuotas,
        ];
    }

    /**
     * Genera un RUT fake pero con formato válido.
     */
    private function generateFakeRut(): string
    {
        $numero = rand(10000000, 25000000);
        $dv = $this->calcularDigitoVerificador($numero);
        return $numero . '-' . $dv;
    }

    /**
     * Calcula el dígito verificador de un RUT chileno.
     */
    private function calcularDigitoVerificador(int $rut): string
    {
        $suma = 0;
        $multiplo = 2;

        while ($rut > 0) {
            $suma += ($rut % 10) * $multiplo;
            $rut = (int) ($rut / 10);
            $multiplo = $multiplo < 7 ? $multiplo + 1 : 2;
        }

        $resto = $suma % 11;
        $dv = 11 - $resto;

        if ($dv == 11) return '0';
        if ($dv == 10) return 'K';
        return (string) $dv;
    }
}
