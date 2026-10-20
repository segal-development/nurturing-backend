<?php

namespace Tests\Feature\Services;

use App\Models\Envio;
use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use App\Models\Prospecto;
use App\Models\ProspectoEnFlujo;
use App\Models\TipoProspecto;
use App\Models\User;
use App\Services\AthenaCampaignService;
use App\Services\DesuscripcionService;
use App\Services\Email\CertificadaEmailService;
use App\Services\Email\EmailProviderResolver;
use App\Services\Email\SmtpEmailService;
use App\Services\EmailValidationService;
use App\Services\EnvioService;
use App\Services\GuardedTransition;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Safe retry of SMS that provably were NOT sent (transport failure before the request reached the provider).
 * Everything else stays terminal (at-most-once).
 *
 * Uses truncation instead of a wrapping transaction: PostgreSQL aborts the whole transaction after a
 * unique violation, which would break the follow-up queries the service runs in production.
 */
class EnvioServiceSmsSafeRetryTest extends TestCase
{
    use DatabaseTruncation;

    private EnvioService $envioService;

    private MockInterface $athenaService;

    private Flujo $flujo;

    private FlujoEjecucionEtapa $etapa;

    private ProspectoEnFlujo $pef;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('envios.sms_retry.enabled', true);
        config()->set('envios.sms_retry.max_attempts', 5);
        config()->set('envios.sms_retry.window_minutes', 180);

        $this->athenaService = Mockery::mock(AthenaCampaignService::class);
        $this->envioService = new EnvioService(
            $this->athenaService,
            $this->app->make(DesuscripcionService::class),
            $this->app->make(EmailValidationService::class),
            new EmailProviderResolver(new SmtpEmailService, new CertificadaEmailService),
            $this->app->make(GuardedTransition::class),
        );

        $tipoProspecto = TipoProspecto::factory()->create();
        $this->flujo = Flujo::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'user_id' => User::factory()->create()->id,
            'es_perpetuo' => true,
            'config_structure' => [
                'stages' => [
                    ['id' => 'start-1', 'type' => 'start', 'label' => 'Start'],
                    ['id' => 'stage-1', 'type' => 'sms', 'label' => 'Stage 1'],
                    ['id' => 'stage-2', 'type' => 'sms', 'label' => 'Stage 2'],
                    ['id' => 'end-1', 'type' => 'end', 'label' => 'End'],
                ],
                'branches' => [
                    ['source_node_id' => 'start-1', 'target_node_id' => 'stage-1'],
                    ['source_node_id' => 'stage-1', 'target_node_id' => 'stage-2'],
                    ['source_node_id' => 'stage-2', 'target_node_id' => 'end-1'],
                ],
                'initial_node' => 'start-1',
            ],
        ]);
        $ejecucion = FlujoEjecucion::factory()->inProgress()->create([
            'flujo_id' => $this->flujo->id,
            'es_perpetuo' => true,
            'prospectos_ids' => [],
            'nodo_actual' => 'stage-2',
        ]);
        $this->etapa = FlujoEjecucionEtapa::factory()->create([
            'flujo_ejecucion_id' => $ejecucion->id,
            'node_id' => 'stage-2',
            'estado' => 'executing',
        ]);
        $prospecto = Prospecto::factory()->create([
            'tipo_prospecto_id' => $tipoProspecto->id,
            'telefono' => '+5491100000000',
        ]);
        $this->pef = ProspectoEnFlujo::factory()->create([
            'flujo_id' => $this->flujo->id,
            'prospecto_id' => $prospecto->id,
            'canal_asignado' => 'sms',
            'ultima_etapa_node_id' => 'stage-1',
            'fecha_inicio' => now()->subDays(60),
            'completado' => false,
            'cancelado' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        // DatabaseTruncation only truncates BEFORE each test; leave the database clean for RefreshDatabase suites.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function enviarSms(): array
    {
        return $this->envioService->enviarSmsAProspecto($this->pef, 'Hello', $this->flujo->id, $this->etapa->id);
    }

    private function failNextSendWith(\Throwable $e): void
    {
        $this->athenaService->shouldReceive('enviarMensaje')->once()->andThrow($e)->ordered();
    }

    private function refused(): ConnectionException
    {
        return new ConnectionException('cURL error 7: Failed to connect to api.example.test port 443: Connection refused');
    }

    private function crearEnvioSms(string $estado, array $metadata): Envio
    {
        return Envio::factory()->create([
            'prospecto_id' => $this->pef->prospecto_id,
            'prospecto_en_flujo_id' => $this->pef->id,
            'flujo_id' => $this->flujo->id,
            'flujo_ejecucion_etapa_id' => $this->etapa->id,
            'canal' => 'sms',
            'estado' => $estado,
            'destinatario' => '+5491100000000',
            'metadata' => $metadata,
        ]);
    }

    private function position(): ?string
    {
        return $this->pef->fresh()->ultima_etapa_node_id;
    }

    /**
     * @test
     *
     * @dataProvider messages
     */
    public function classifier_accepts_only_whitelisted_pre_send_failures(\Throwable $e, bool $retryable): void
    {
        $this->athenaService->shouldReceive('enviarMensaje')->once()->andThrow($e);

        $result = $this->enviarSms();

        $this->assertFalse($result['success']);
        $envio = Envio::findOrFail($result['envio_id']);
        $this->assertSame('fallido', $envio->estado);
        $this->assertSame($retryable, ($envio->metadata['reintentable'] ?? false) === true);
    }

    public static function messages(): array
    {
        return [
            'cURL 7 refused' => [new ConnectionException('cURL error 7: Failed to connect to api.example.test port 443: Connection refused'), true],
            'cURL 28 connect timeout' => [new ConnectionException('cURL error 28: Connection timed out after 10000 milliseconds'), true],
            'cURL 28 resolving timeout' => [new ConnectionException('cURL error 28: Resolving timed out after 10000 milliseconds'), true],
            'cURL 6 resolve' => [new ConnectionException('cURL error 6: Could not resolve host: api.example.test'), true],
            'cURL 28 operation timeout' => [new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 out of 0 bytes received'), false],
            // Guzzle raises a RequestException (not a ConnectException) for cURL 56, so Laravel does not wrap it.
            'cURL 56 recv failure' => [new RequestException('cURL error 56: Recv failure: Connection reset by peer', new Request('GET', 'https://api.example.test')), false],
            // Guzzle appends the request URL (which carries the user-controlled SMS body) after the cURL text.
            'ambiguous timeout with body mimicking connect timeout' => [new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 out of 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://x/sendmessage?MESSAGE=Connection timed out'), false],
            'ambiguous timeout with body mimicking resolve timeout' => [new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 out of 0 bytes received for https://x/sendmessage?MESSAGE=Resolving timed out'), false],
            'recv failure with body mimicking cURL 7' => [new ConnectionException('cURL error 56: Recv failure for https://x/sendmessage?MESSAGE=cURL error 7: Failed to connect'), false],
            'plain exception with curl text' => [new \Exception('cURL error 7: Failed to connect'), false],
            'HTTP 503' => [new \Exception('Error enviando SMS: HTTP 503'), false],
            'statusERROR' => [new \Exception('Error enviando SMS: statusERROR'), false],
            'query exception' => [new QueryException('pgsql', 'select 1', [], new \PDOException('cURL error 7: boom', 0)), false],
        ];
    }

    /** @test */
    public function pre_send_failure_is_marked_retryable_and_does_not_advance_position(): void
    {
        $this->failNextSendWith($this->refused());

        $result = $this->enviarSms();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cURL error 7', $result['error']);
        $envio = Envio::findOrFail($result['envio_id']);
        $this->assertSame('fallido', $envio->estado);
        $this->assertTrue($envio->metadata['reintentable']);
        $this->assertSame(1, $envio->metadata['intentos']);
        $this->assertSame($result['error'], $envio->metadata['ultimo_error']);
        $this->assertSame('stage-1', $this->position());
    }

    /** @test */
    public function ambiguous_failure_stays_terminal_without_retry_metadata(): void
    {
        $this->failNextSendWith(new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds with 0 out of 0 bytes received'));

        $result = $this->enviarSms();

        $envio = Envio::findOrFail($result['envio_id']);
        $this->assertSame('fallido', $envio->estado);
        $this->assertArrayNotHasKey('reintentable', $envio->metadata);
        $this->assertArrayNotHasKey('intentos', $envio->metadata);
    }

    /** @test */
    public function retry_reuses_the_same_row_sends_once_and_advances_position(): void
    {
        $this->failNextSendWith($this->refused());
        $first = $this->enviarSms();
        $this->athenaService->shouldReceive('enviarMensaje')->once()->andReturn(['success' => true])->ordered();

        $second = $this->enviarSms();

        $this->assertTrue($second['success']);
        $this->assertSame($first['envio_id'], $second['envio_id']);
        $this->assertSame(1, Envio::count());
        $this->assertSame('enviado', Envio::first()->estado);
        $this->assertSame('stage-2', $this->position());
    }

    /** @test */
    public function retry_failing_with_an_ambiguous_error_becomes_terminal(): void
    {
        $this->failNextSendWith($this->refused());
        $this->enviarSms();
        $this->failNextSendWith(new \Exception('Error enviando SMS: HTTP 503'));
        $this->enviarSms();
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $third = $this->enviarSms();

        $this->assertTrue($third['skipped']);
        $this->assertFalse(Envio::first()->metadata['reintentable']);
        $this->assertSame(1, Envio::count());
    }

    /** @test */
    public function a_nested_call_while_the_row_is_in_flight_is_skipped_by_the_pre_check(): void
    {
        $this->failNextSendWith($this->refused());
        $this->enviarSms();

        $inner = null;
        $this->athenaService->shouldReceive('enviarMensaje')->once()->andReturnUsing(function () use (&$inner) {
            // While the first worker holds the row ('pendiente'), a second worker tries to claim it.
            $inner = $this->enviarSms();

            return ['success' => true];
        });

        $outer = $this->enviarSms();

        $this->assertTrue($outer['success']);
        $this->assertTrue($inner['skipped']);
        $this->assertSame(1, Envio::count());
    }

    /** @test */
    public function the_loser_of_the_atomic_claim_neither_sends_inserts_nor_advances_position(): void
    {
        $this->failNextSendWith($this->refused());
        $this->enviarSms();

        // Deterministic seam: right after the claim's read finds the row, another worker claims it
        // (raw update), so this worker's conditional UPDATE matches zero rows.
        $flipped = false;
        DB::listen(function ($query) use (&$flipped) {
            if (! $flipped && str_starts_with($query->sql, 'select "id" from "envios"')) {
                $flipped = true;
                DB::table('envios')->update(['estado' => 'pendiente']);
            }
        });
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $loser = $this->enviarSms();

        $this->assertTrue($flipped);
        $this->assertTrue($loser['skipped']);
        $this->assertNull($loser['envio_id']);
        $this->assertSame(1, Envio::count());
        $this->assertSame('pendiente', Envio::first()->estado);
        $this->assertSame('stage-1', $this->position());
    }

    /** @test */
    public function skip_on_a_retryable_row_does_not_advance_position(): void
    {
        $this->crearEnvioSms('pendiente', ['reintentable' => true, 'intentos' => 1]);
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $result = $this->enviarSms();

        $this->assertTrue($result['skipped']);
        $this->assertSame('stage-1', $this->position());
    }

    /** @test */
    public function skip_on_a_non_retryable_terminal_row_still_advances_position(): void
    {
        $this->crearEnvioSms('fallido', ['reintentable' => false, 'intentos' => 5]);
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $result = $this->enviarSms();

        $this->assertTrue($result['skipped']);
        $this->assertSame('stage-2', $this->position());
    }

    /** @test */
    public function skip_on_a_retryable_row_advances_position_when_the_feature_is_disabled(): void
    {
        config()->set('envios.sms_retry.enabled', false);
        $this->crearEnvioSms('fallido', ['reintentable' => true, 'intentos' => 1]);
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $result = $this->enviarSms();

        $this->assertTrue($result['skipped']);
        $this->assertSame('stage-2', $this->position());
    }

    /** @test */
    public function row_is_terminal_once_max_attempts_is_reached(): void
    {
        config()->set('envios.sms_retry.max_attempts', 2);
        $this->failNextSendWith($this->refused());
        $this->enviarSms();
        $this->failNextSendWith($this->refused());
        $this->enviarSms();

        $envio = Envio::first();
        $this->assertSame(2, $envio->metadata['intentos']);
        $this->assertFalse($envio->metadata['reintentable']);

        $this->athenaService->shouldNotReceive('enviarMensaje');
        $third = $this->enviarSms();

        $this->assertTrue($third['skipped']);
        $this->assertSame(1, Envio::count());
        $this->assertSame('fallido', Envio::first()->estado);
    }

    /** @test */
    public function retryable_row_older_than_the_window_is_not_claimed(): void
    {
        $this->failNextSendWith($this->refused());
        $this->enviarSms();
        Envio::query()->update(['created_at' => now()->subMinutes(181)]);
        $this->athenaService->shouldNotReceive('enviarMensaje');

        $second = $this->enviarSms();

        $this->assertTrue($second['skipped']);
        $this->assertSame('fallido', Envio::first()->estado);
    }

    /** @test */
    public function disabled_by_default_keeps_failed_rows_terminal_without_metadata(): void
    {
        config()->set('envios.sms_retry.enabled', false);
        $this->failNextSendWith($this->refused());

        $first = $this->enviarSms();

        $envio = Envio::findOrFail($first['envio_id']);
        $this->assertSame('fallido', $envio->estado);
        $this->assertArrayNotHasKey('reintentable', $envio->metadata);
        $this->assertArrayNotHasKey('intentos', $envio->metadata);
        $this->assertArrayNotHasKey('ultimo_error', $envio->metadata);

        $this->athenaService->shouldNotReceive('enviarMensaje');
        $second = $this->enviarSms();

        $this->assertTrue($second['skipped']);
        $this->assertSame(1, Envio::count());
    }

    /** @test */
    public function config_default_is_off(): void
    {
        $defaults = (require base_path('config/envios.php'))['sms_retry'];
        $this->assertFalse($defaults['enabled']);
        $this->assertSame(5, $defaults['max_attempts']);
        $this->assertSame(180, $defaults['window_minutes']);
    }
}
