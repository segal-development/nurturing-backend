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
        });

        $this->command->info('');
        $this->command->info('✅ Datos fake creados exitosamente!');
        $this->command->info('');
        $this->command->info('Ahora puedes:');
        $this->command->info('  1. Crear un flujo seleccionando el origen "Grupo Deudas"');
        $this->command->info('  2. Seleccionar el lote "CONTRATOS_NUEVOS_TEST" o "CUOTAS_VENCIDAS_TEST"');
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
                    'metadata' => $this->buildContratosMetadata($index + 1),
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
                'metadata' => $this->buildContratosMetadata($index + 1),
            ]);
            $creados++;
        }

        // Agregar algunos prospectos fake adicionales (sin contacto real)
        for ($i = 0; $i < 5; $i++) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);
            $fakeName = self::FAKE_NAMES[$i];
            $fakeEmail = 'fake.contrato.' . ($i + 1) . '@test.local';

            // Skip si ya existe
            if (Prospecto::where('email', $fakeEmail)->exists()) {
                continue;
            }

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => '+5691234' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT),
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildContratosMetadata($i + 100),
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

            // Verificar si ya existe
            if (Prospecto::where('email', $email)->exists()) {
                $this->command->warn("  ⚠️  Email {$email} ya existe, saltando...");
                continue;
            }

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => str_replace('(Prueba)', '(Cuotas Vencidas)', $contact['nombre']),
                'email' => $email,
                'telefono' => $contact['telefono'],
                'rut' => $contact['rut'] ? str_replace('.', '', $contact['rut']) : null,
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildCuotasVencidasMetadata($index + 1, $contact['rut']),
            ]);
            $creados++;
        }

        // Agregar algunos prospectos fake adicionales
        for ($i = 0; $i < 5; $i++) {
            $montoDeuda = $this->getRandomMontoDeuda();
            $tipoProspecto = $this->getTipoProspectoPorMonto($tiposProspecto, $montoDeuda);
            $fakeName = self::FAKE_NAMES[$i + 5] ?? self::FAKE_NAMES[$i];
            $fakeEmail = 'fake.cuotas.' . ($i + 1) . '@test.local';

            if (Prospecto::where('email', $fakeEmail)->exists()) {
                continue;
            }

            Prospecto::create([
                'importacion_id' => $importacion->id,
                'nombre' => $fakeName,
                'email' => $fakeEmail,
                'telefono' => '+5698765' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT),
                'rut' => $this->generateFakeRut(),
                'tipo_prospecto_id' => $tipoProspecto->id,
                'estado' => 'activo',
                'monto_deuda' => $montoDeuda,
                'metadata' => $this->buildCuotasVencidasMetadata($i + 100, null),
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
     */
    private function buildContratosMetadata(int $contratoId): array
    {
        return [
            'source' => 'grupo_deuda',
            'endpoint' => 'contratos_nuevos',
            'synced_at' => now()->toISOString(),
            'contrato_id' => $contratoId,
            'cuotas' => rand(12, 48),
            'vigencia' => now()->addMonths(rand(12, 48))->format('Y-m-d'),
            'vendedor' => 'Vendedor Prueba',
            'is_fake_data' => true,
        ];
    }

    /**
     * Construye metadata para Cuotas Vencidas.
     */
    private function buildCuotasVencidasMetadata(int $clienteId, ?string $rut): array
    {
        $cuotasVencidas = [];
        $numCuotas = rand(1, 3);

        for ($i = 0; $i < $numCuotas; $i++) {
            $cuotasVencidas[] = [
                'numero' => $i + 1,
                'monto' => rand(50000, 200000),
                'fecha_vencimiento' => now()->subDays(rand(1, 30))->format('Y-m-d'),
            ];
        }

        return [
            'source' => 'grupo_deuda',
            'endpoint' => 'cuotas_vencidas',
            'synced_at' => now()->toISOString(),
            'cliente_id' => $clienteId,
            'rut_original' => $rut,
            'abogado' => 'Abogado Prueba',
            'cuotas' => $cuotasVencidas,
            'is_fake_data' => true,
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
