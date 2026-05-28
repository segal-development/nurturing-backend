<?php

namespace App\Jobs;

use App\Models\ExternalApiSource;
use App\Services\GrupoDeudaApiSyncService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Genera, on-demand, el export/reconcile de SYSGAL (contratos o clientes-ingreso) para
 * un rango de fechas arbitrario que el usuario elige en el dashboard.
 *
 * Por qué un job (y no la API directo): la API/Cloud Run NO está whitelisteada en SYSGAL.
 * Solo la VM puede consultar SYSGAL. Este job se despacha a la cola 'sysgal', que SOLO
 * procesa la VM (whitelisteada). OJO: 'default' es una cola COMPARTIDA VM+Cloud Run, así que
 * NO sirve para jobs que llaman a SYSGAL (Cloud Run los tomaría y daría 403). El resultado
 * (conteo + CSV) se deja en la tabla `cache` con key literal 'export-sysgal:{token}',
 * compartida con la API (Cloud SQL), que la lee para el status y la descarga.
 */
class GenerarExportSysgalJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(
        public string $token,
        public string $tipo,   // 'contratos' | 'clientes-ingreso'
        public string $desde,  // Y-m-d
        public string $hasta,  // Y-m-d
    ) {
        $this->onQueue('sysgal'); // cola dedicada que SOLO procesa la VM (whitelisteada en SYSGAL)
    }

    public function handle(GrupoDeudaApiSyncService $svc): void
    {
        $sourceName = $this->tipo === 'contratos' ? 'grupo_deuda_contratos' : 'grupo_deuda_clientes_ingreso';
        $src = ExternalApiSource::where('name', $sourceName)->first();

        if (! $src) {
            $this->store(['estado' => 'error', 'error' => "Fuente {$sourceName} no encontrada"]);

            return;
        }

        $desde = Carbon::parse($this->desde)->startOfDay();
        $hasta = Carbon::parse($this->hasta)->endOfDay();

        $rows = $svc->fetchRowsForReconciliation($src, $this->tipo, $desde, $hasta);
        $rechazados = $this->computeRechazados($this->tipo, $rows);

        $this->store([
            'estado' => 'listo',
            'count' => count($rows),
            'rechazados' => $rechazados,
            'tipo' => $this->tipo,
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'csv' => $this->buildCsv($this->tipo, $rows),
            'generado_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Para cada cliente que SYSGAL reportó, identifica si está en el flujo onboarding
     * correspondiente y, si NO, por qué. La gerencia necesita saber con detalle qué
     * pasó con cada uno: si tiene datos malos (corregir en SYSGAL) o si ya estaba en
     * otro flujo (situación legítima, se omitió).
     *
     * Razones que puede devolver:
     *  - sin_nombre, sin_email, email_invalido, sin_telefono: datos del SYSGAL malos.
     *  - en_otro_flujo_activo: el prospecto ya estaba en otro flujo (doble-membresía).
     *  - no_se_creo: tiene contacto válido pero por alguna razón no se creó en DB.
     *  - no_asignado: existe en DB pero no se asignó al flujo (raro, investigar).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function computeRechazados(string $tipo, array $rows): array
    {
        $flujoId = $tipo === 'contratos' ? 48 : 49;
        $rechazados = [];

        foreach ($rows as $r) {
            $email = isset($r['Email']) ? strtolower(trim((string) $r['Email'])) : '';
            $telefono = isset($r['Telefono']) ? trim((string) $r['Telefono']) : '';
            $rutRaw = (string) ($r['Rut'] ?? '');
            $rutNorm = str_replace('.', '', trim($rutRaw));

            $nombre = $tipo === 'contratos'
                ? trim((string) ($r['Cliente'] ?? ''))
                : trim(((string) ($r['Nombre'] ?? '')).' '.((string) ($r['Apellido_Paterno'] ?? '')).' '.((string) ($r['Apellido_Materno'] ?? '')));

            // 1) Buscar TODOS los prospectos que pueden ser este cliente. La DB tiene
            // prospectos duplicados con mismo email/RUT (legacy de syncs viejos) y a veces
            // SYSGAL trae el email con typo o el teléfono sin prefijo +56. Probamos varias
            // variantes para no marcar como rechazado a alguien que SÍ está en el flujo.
            $prospectoIds = [];
            if ($rutNorm !== '') {
                $prospectoIds = array_merge($prospectoIds, DB::table('prospectos')->where('rut', $rutNorm)->pluck('id')->toArray());
            }
            if ($email !== '') {
                $prospectoIds = array_merge($prospectoIds, DB::table('prospectos')->where('email', $email)->pluck('id')->toArray());
            }
            if ($telefono !== '') {
                $telDigits = preg_replace('/\D/', '', $telefono);
                $telBase = ltrim($telDigits, '56');
                $variantesTel = array_values(array_unique(array_filter([
                    $telefono,
                    $telDigits,
                    '+56'.$telBase,
                    '56'.$telBase,
                ])));
                $prospectoIds = array_merge($prospectoIds, DB::table('prospectos')->whereIn('telefono', $variantesTel)->pluck('id')->toArray());
            }
            $prospectoIds = array_values(array_unique($prospectoIds));

            // 2) Si CUALQUIERA de los prospectos matcheados está en el flujo onboarding,
            // no es rechazado (el cliente recibe el email via su prospecto duplicado).
            if (! empty($prospectoIds)) {
                $enFlujo = DB::table('prospecto_en_flujo')
                    ->whereIn('prospecto_id', $prospectoIds)
                    ->where('flujo_id', $flujoId)
                    ->where('cancelado', false)
                    ->exists();
                if ($enFlujo) {
                    continue;
                }
            }
            $prospectoId = $prospectoIds[0] ?? null; // para mantener el resto de la lógica

            // 3) NO está en el flujo. Determinar la razón.
            $razones = [];

            if ($nombre === '') {
                $razones[] = 'sin_nombre';
            }
            if ($email === '') {
                $razones[] = 'sin_email';
            } elseif (! $this->isValidEmail($email)) {
                $razones[] = 'email_invalido';
            }
            if ($telefono === '' || $telefono === '0') {
                $razones[] = 'sin_telefono';
            }

            // 4) Si tiene contacto + nombre, debió haber entrado. Buscar la razón fina.
            $emailValido = $email !== '' && $this->isValidEmail($email);
            $telefonoValido = $telefono !== '' && $telefono !== '0';
            if (($emailValido || $telefonoValido) && $nombre !== '') {
                if (! empty($prospectoIds)) {
                    // Existe en DB (en algún prospecto duplicado o único) pero ninguno en este
                    // flujo. ¿Está en otro flujo activo (doble-membresía pendiente)?
                    $enOtroFlujo = DB::table('prospecto_en_flujo')
                        ->whereIn('prospecto_id', $prospectoIds)
                        ->where('cancelado', false)
                        ->where('flujo_id', '!=', $flujoId)
                        ->exists();
                    $razones[] = $enOtroFlujo ? 'en_otro_flujo_activo' : 'no_asignado';
                } else {
                    $razones[] = 'no_se_creo';
                }
            }

            $rechazados[] = [
                'rut' => $rutRaw,
                'nombre' => $nombre,
                'email' => (string) ($r['Email'] ?? ''),
                'telefono' => (string) ($r['Telefono'] ?? ''),
                'razones' => $razones,
            ];
        }

        return $rechazados;
    }

    /**
     * Mismo criterio que GrupoDeudaApiSyncService::isValidEmail (lista de inválidos
     * conocidos + filter_var + longitud mínima). Replicado acá para mantener el job
     * autocontenido y no tocar visibilidad del método privado.
     */
    private function isValidEmail(string $email): bool
    {
        $invalidos = ['s@c', 'n@g', 'sin@correo', 'sc@sc.cl', 'sin@correo.cl', 'no@tiene.cl', 's@c.cl', 'notiene@notiene.cl'];
        if (in_array($email, $invalidos, true)) {
            return false;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (strlen($email) < 6) {
            return false;
        }

        return true;
    }

    public function failed(\Throwable $e): void
    {
        $this->store([
            'estado' => 'error',
            'error' => 'No se pudo generar el export: '.$e->getMessage(),
        ]);
    }

    private function store(array $payload): void
    {
        DB::table('cache')->updateOrInsert(
            ['key' => 'export-sysgal:'.$this->token],
            ['value' => json_encode($payload), 'expiration' => now()->addHours(2)->timestamp]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildCsv(string $tipo, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel

        if ($tipo === 'contratos') {
            fputcsv($fh, ['Id', 'Cliente', 'Rut', 'Email', 'Telefono', 'Monto', 'Cuotas', 'Vigencia_Inicio', 'Vigencia_Termino', 'Vendedor', 'Vendedor_Email']);
            foreach ($rows as $r) {
                fputcsv($fh, [$r['Id'] ?? '', $r['Cliente'] ?? '', $r['Rut'] ?? '', $r['Email'] ?? '', $r['Telefono'] ?? '', $r['Monto'] ?? '', $r['Cuotas'] ?? '', $r['Vigencia']['Inicio'] ?? '', $r['Vigencia']['Termino'] ?? '', $r['Vendedor']['Nombre'] ?? '', $r['Vendedor']['Email'] ?? '']);
            }
        } else {
            fputcsv($fh, ['Id', 'Nombre', 'Email', 'Telefono', 'Abogado', 'Abogado_Email']);
            foreach ($rows as $r) {
                $nombre = trim(($r['Nombre'] ?? '').' '.($r['Apellido_Paterno'] ?? '').' '.($r['Apellido_Materno'] ?? ''));
                $abogado = trim(($r['Abogado']['Nombre'] ?? '').' '.($r['Abogado']['Apellido_Paterno'] ?? ''));
                fputcsv($fh, [$r['Id'] ?? '', $nombre, $r['Email'] ?? '', $r['Telefono'] ?? '', $abogado, $r['Abogado']['Email'] ?? '']);
            }
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }
}
