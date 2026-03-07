<?php

namespace Tests\Feature\Services;

use App\Models\ExternalApiSource;
use App\Models\Lote;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\SysgalApiSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests de integración para el sync de Sysgal.
 *
 * Verifica que:
 * - La fuente unificada con múltiples endpoints funciona correctamente
 * - El monto de deuda se parsea correctamente desde TotalDeuda
 * - El nivel de deuda se calcula y guarda en metadata
 * - Los prospectos se crean correctamente con todos los campos
 */
class SysgalSyncIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ExternalApiSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear un usuario para las relaciones FK
        User::factory()->create(['id' => 1]);

        // Crear tipos de prospecto necesarios
        TipoProspecto::factory()->create([
            'nombre' => 'Deuda Baja',
            'monto_min' => 0,
            'monto_max' => 699999,
        ]);

        TipoProspecto::factory()->create([
            'nombre' => 'Deuda Media',
            'monto_min' => 700000,
            'monto_max' => 1499999,
        ]);

        TipoProspecto::factory()->create([
            'nombre' => 'Deuda Alta',
            'monto_min' => 1500000,
            'monto_max' => 999999999,
        ]);

        // Crear source unificada de Sysgal con múltiples endpoints
        $this->source = ExternalApiSource::factory()->create([
            'name' => 'sysgal',
            'display_name' => 'Sysgal (Defensoría)',
            'endpoint_url' => 'https://sysgal.segal.cl/defensoria/Servicio',
            'auth_type' => 'none',
            'field_mapping' => [
                'nombre' => 'Nombre',
                'rut' => 'Rut',
                'email' => 'Email',
                'telefono' => 'Telefono',
                'monto_deuda' => 'TotalDeuda',
            ],
            'sync_filters' => [
                'dias_atras' => 7,
                'unificar_lotes' => true,
                'lote_global' => 'SYSGAL',
                'endpoints' => [
                    [
                        'name' => 'no_agendados',
                        'url' => 'https://sysgal.segal.cl/defensoria/Servicio/ProspectosNoAgendados',
                        'display_name' => 'Prospectos No Agendados',
                        'field_mapping' => [
                            'nombre' => 'Nombre',
                            'rut' => 'Rut',
                            'email' => 'Email',
                            'telefono' => 'Telefono',
                            'monto_deuda' => 'TotalDeuda',
                            'etapa_sysgal' => 'Etapa',
                        ],
                        'date_format' => 'Y-m-d',
                    ],
                    [
                        'name' => 'no_cerrados',
                        'url' => 'https://sysgal.segal.cl/defensoria/Servicio/AgendadosNoCerrados',
                        'display_name' => 'Agendas No Cerradas',
                        'field_mapping' => [
                            'nombre' => 'Cliente.Nombre',
                            'rut' => 'Cliente.Rut',
                            'email' => 'Cliente.Email',
                            'telefono' => 'Cliente.Telefono',
                            'monto_deuda' => 'Cliente.TotalDeuda',
                        ],
                        'date_format' => 'Y-m-d H:i:s',
                    ],
                ],
            ],
            'lote_prefix' => 'SYSGAL',
            'is_active' => true,
        ]);
    }

    public function test_sync_crea_prospectos_con_monto_deuda(): void
    {
        // Mock de la respuesta de Sysgal para ambos endpoints
        Http::fake([
            '*ProspectosNoAgendados*' => Http::response([
                'Estado' => 1,
                'Total' => 2,
                'Prospectos' => [
                    [
                        'Nombre' => 'Juan Pérez',
                        'Rut' => '12.345.678-9',
                        'Email' => 'juan@email.com',
                        'Telefono' => '912345678',
                        'Etapa' => 'Pendiente de Contactar',
                        'TotalDeuda' => '2500400',
                    ],
                    [
                        'Nombre' => 'María López',
                        'Rut' => '11.222.333-4',
                        'Email' => 'maria@email.com',
                        'Telefono' => '987654321',
                        'Etapa' => 'Solo Consulta',
                        'TotalDeuda' => '500000',
                    ],
                ],
            ], 200),
            '*AgendadosNoCerrados*' => Http::response([
                'Estado' => 1,
                'Total' => 0,
                'Agendas' => [],
            ], 200),
        ]);

        $service = new SysgalApiSyncService;
        $result = $service->sync($this->source, 1);

        // Verificar que se crearon los prospectos
        $this->assertEquals(2, $result['nuevos']);

        // Verificar prospecto con deuda alta
        $juan = Prospecto::where('email', 'juan@email.com')->first();
        $this->assertNotNull($juan);
        $this->assertEquals(2500400, $juan->monto_deuda);
        $this->assertEquals('alta', $juan->metadata['nivel_deuda']);
        $this->assertEquals('no_agendados', $juan->metadata['endpoint']);

        // Verificar prospecto con deuda baja
        $maria = Prospecto::where('email', 'maria@email.com')->first();
        $this->assertNotNull($maria);
        $this->assertEquals(500000, $maria->monto_deuda);
        $this->assertEquals('baja', $maria->metadata['nivel_deuda']);
    }

    public function test_sync_procesa_multiples_endpoints(): void
    {
        // Mock con datos de ambos endpoints
        Http::fake([
            '*ProspectosNoAgendados*' => Http::response([
                'Estado' => 1,
                'Total' => 1,
                'Prospectos' => [
                    [
                        'Nombre' => 'Prospecto No Agendado',
                        'Rut' => '11.111.111-1',
                        'Email' => 'noagendado@test.com',
                        'Telefono' => '911111111',
                        'Etapa' => 'Pendiente',
                        'TotalDeuda' => '800000',
                    ],
                ],
            ], 200),
            '*AgendadosNoCerrados*' => Http::response([
                'Estado' => 1,
                'Total' => 1,
                'Agendas' => [
                    [
                        'Cliente' => [
                            'Nombre' => 'Prospecto No Cerrado',
                            'Rut' => '22.222.222-2',
                            'Email' => 'nocerrado@test.com',
                            'Telefono' => '922222222',
                            'TotalDeuda' => '1600000',
                        ],
                        'Reunion' => [
                            'Tiempo' => '2026-03-01 10:00:00',
                            'Estado_Final' => 'NO CONTRATA',
                            'Comercial' => 'Test User',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new SysgalApiSyncService;
        $result = $service->sync($this->source, 1);

        // Verificar que se procesaron ambos endpoints
        $this->assertEquals(2, $result['nuevos']);

        // Verificar prospecto de no_agendados
        $noAgendado = Prospecto::where('email', 'noagendado@test.com')->first();
        $this->assertNotNull($noAgendado);
        $this->assertEquals('no_agendados', $noAgendado->metadata['endpoint']);
        $this->assertEquals('media', $noAgendado->metadata['nivel_deuda']);

        // Verificar prospecto de no_cerrados
        $noCerrado = Prospecto::where('email', 'nocerrado@test.com')->first();
        $this->assertNotNull($noCerrado);
        $this->assertEquals('no_cerrados', $noCerrado->metadata['endpoint']);
        $this->assertEquals('alta', $noCerrado->metadata['nivel_deuda']);
    }

    public function test_sync_crea_lote_global_sysgal(): void
    {
        Http::fake([
            '*ProspectosNoAgendados*' => Http::response([
                'Estado' => 1,
                'Total' => 1,
                'Prospectos' => [
                    [
                        'Nombre' => 'Test User',
                        'Rut' => '11.111.111-1',
                        'Email' => 'test@email.com',
                        'Telefono' => '911111111',
                        'Etapa' => 'Pendiente de Contactar',
                        'TotalDeuda' => '1000000',
                    ],
                ],
            ], 200),
            '*AgendadosNoCerrados*' => Http::response([
                'Estado' => 1,
                'Total' => 0,
                'Agendas' => [],
            ], 200),
        ]);

        $service = new SysgalApiSyncService;
        $service->sync($this->source, 1);

        // Verificar que se creó el lote global
        $lote = Lote::where('nombre', 'SYSGAL')->first();
        $this->assertNotNull($lote);
    }

    public function test_sync_clasifica_nivel_deuda_correctamente(): void
    {
        Http::fake([
            '*ProspectosNoAgendados*' => Http::response([
                'Estado' => 1,
                'Total' => 4,
                'Prospectos' => [
                    [
                        'Nombre' => 'Deuda Baja User',
                        'Rut' => '11.111.111-1',
                        'Email' => 'baja@test.com',
                        'Telefono' => '911111111',
                        'Etapa' => 'Test',
                        'TotalDeuda' => '350000', // < 700k = baja
                    ],
                    [
                        'Nombre' => 'Deuda Media User',
                        'Rut' => '22.222.222-2',
                        'Email' => 'media@test.com',
                        'Telefono' => '922222222',
                        'Etapa' => 'Test',
                        'TotalDeuda' => '950000', // 700k-1.5M = media
                    ],
                    [
                        'Nombre' => 'Deuda Alta User',
                        'Rut' => '33.333.333-3',
                        'Email' => 'alta@test.com',
                        'Telefono' => '933333333',
                        'Etapa' => 'Test',
                        'TotalDeuda' => '2000000', // > 1.5M = alta
                    ],
                    [
                        'Nombre' => 'Sin Deuda User',
                        'Rut' => '44.444.444-4',
                        'Email' => 'sininfo@test.com',
                        'Telefono' => '944444444',
                        'Etapa' => 'Test',
                        'TotalDeuda' => '0', // 0 = sin_informacion
                    ],
                ],
            ], 200),
            '*AgendadosNoCerrados*' => Http::response([
                'Estado' => 1,
                'Total' => 0,
                'Agendas' => [],
            ], 200),
        ]);

        $service = new SysgalApiSyncService;
        $service->sync($this->source, 1);

        // Verificar clasificaciones
        $this->assertEquals('baja', Prospecto::where('email', 'baja@test.com')->first()->metadata['nivel_deuda']);
        $this->assertEquals('media', Prospecto::where('email', 'media@test.com')->first()->metadata['nivel_deuda']);
        $this->assertEquals('alta', Prospecto::where('email', 'alta@test.com')->first()->metadata['nivel_deuda']);
        $this->assertEquals('sin_informacion', Prospecto::where('email', 'sininfo@test.com')->first()->metadata['nivel_deuda']);
    }

    public function test_sync_maneja_monto_deuda_con_formato_string(): void
    {
        Http::fake([
            '*ProspectosNoAgendados*' => Http::response([
                'Estado' => 1,
                'Total' => 1,
                'Prospectos' => [
                    [
                        'Nombre' => 'String Monto User',
                        'Rut' => '12.345.678-9',
                        'Email' => 'string@test.com',
                        'Telefono' => '912345678',
                        'Etapa' => 'Test',
                        'TotalDeuda' => '2.500.400', // Con puntos de miles
                    ],
                ],
            ], 200),
            '*AgendadosNoCerrados*' => Http::response([
                'Estado' => 1,
                'Total' => 0,
                'Agendas' => [],
            ], 200),
        ]);

        $service = new SysgalApiSyncService;
        $service->sync($this->source, 1);

        $prospecto = Prospecto::where('email', 'string@test.com')->first();
        $this->assertEquals(2500400, $prospecto->monto_deuda);
        $this->assertEquals('alta', $prospecto->metadata['nivel_deuda']);
    }

    public function test_sync_con_respuesta_vacia(): void
    {
        Http::fake([
            '*ProspectosNoAgendados*' => Http::response([
                'Estado' => 1,
                'Total' => 0,
                'Prospectos' => [],
            ], 200),
            '*AgendadosNoCerrados*' => Http::response([
                'Estado' => 1,
                'Total' => 0,
                'Agendas' => [],
            ], 200),
        ]);

        $service = new SysgalApiSyncService;
        $result = $service->sync($this->source, 1);

        $this->assertEquals(0, $result['total_prospectos']);
        $this->assertEquals(0, $result['nuevos']);
    }

    public function test_sync_endpoint_especifico(): void
    {
        // Mock ambos endpoints pero solo sincronizaremos uno
        Http::fake([
            '*ProspectosNoAgendados*' => Http::response([
                'Estado' => 1,
                'Total' => 1,
                'Prospectos' => [
                    [
                        'Nombre' => 'Solo No Agendado',
                        'Rut' => '11.111.111-1',
                        'Email' => 'solo@test.com',
                        'Telefono' => '911111111',
                        'Etapa' => 'Test',
                        'TotalDeuda' => '500000',
                    ],
                ],
            ], 200),
            '*AgendadosNoCerrados*' => Http::response([
                'Estado' => 1,
                'Total' => 1,
                'Agendas' => [
                    [
                        'Cliente' => [
                            'Nombre' => 'No Debería Aparecer',
                            'Rut' => '22.222.222-2',
                            'Email' => 'nodeberia@test.com',
                            'Telefono' => '922222222',
                            'TotalDeuda' => '1000000',
                        ],
                        'Reunion' => [],
                    ],
                ],
            ], 200),
        ]);

        $service = new SysgalApiSyncService;
        // Sincronizar solo no_agendados
        $result = $service->sync($this->source, 1, 'no_agendados');

        // Solo debería haber 1 prospecto
        $this->assertEquals(1, $result['nuevos']);
        $this->assertNotNull(Prospecto::where('email', 'solo@test.com')->first());
        $this->assertNull(Prospecto::where('email', 'nodeberia@test.com')->first());
    }

    public function test_test_connection_verifica_todos_los_endpoints(): void
    {
        Http::fake([
            '*ProspectosNoAgendados*' => Http::response([
                'Estado' => 1,
                'Total' => 5,
                'Prospectos' => [],
            ], 200),
            '*AgendadosNoCerrados*' => Http::response([
                'Estado' => 1,
                'Total' => 3,
                'Agendas' => [],
            ], 200),
        ]);

        $service = new SysgalApiSyncService;
        $result = $service->testConnection($this->source);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('endpoints', $result);
        $this->assertArrayHasKey('no_agendados', $result['endpoints']);
        $this->assertArrayHasKey('no_cerrados', $result['endpoints']);
        $this->assertTrue($result['endpoints']['no_agendados']['success']);
        $this->assertTrue($result['endpoints']['no_cerrados']['success']);
    }
}
