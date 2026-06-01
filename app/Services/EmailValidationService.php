<?php

namespace App\Services;

use App\Models\Prospecto;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para validar emails y detectar direcciones inválidas.
 *
 * Detecta automáticamente:
 * - Formato inválido (no cumple RFC 2822)
 * - Dominios mal escritos (gimeil.com, guimei.con, hotmal.com, etc.)
 * - Dominios inexistentes
 * - Errores de bounce (554, 550, etc.)
 *
 * Los prospectos con email inválido son excluidos automáticamente de futuros envíos.
 */
class EmailValidationService
{
    /**
     * Dominios comunes mal escritos y sus correcciones.
     * Basado en errores reales detectados en las importaciones.
     */
    private const DOMINIOS_TYPOS = [
        // Gmail typos
        'gmial.com' => 'gmail.com',
        'gmai.com' => 'gmail.com',
        'gimeil.com' => 'gmail.com',
        'guimei.con' => 'gmail.com',
        'gmaill.com' => 'gmail.com',
        'gmeil.com' => 'gmail.com',
        'gmail.con' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gamil.com' => 'gmail.com',
        'gnail.com' => 'gmail.com',
        'gmsil.com' => 'gmail.com',
        'gmil.com' => 'gmail.com',

        // Hotmail typos
        'hotmal.com' => 'hotmail.com',
        'hotmial.com' => 'hotmail.com',
        'hotmai.com' => 'hotmail.com',
        'hotmail.con' => 'hotmail.com',
        'hotmil.com' => 'hotmail.com',
        'hotmaill.com' => 'hotmail.com',
        'hotamil.com' => 'hotmail.com',
        'homail.com' => 'hotmail.com',
        'htmail.com' => 'hotmail.com',

        // Yahoo typos
        'yaho.com' => 'yahoo.com',
        'yahooo.com' => 'yahoo.com',
        'yahoo.con' => 'yahoo.com',
        'yhaoo.com' => 'yahoo.com',
        'yaoo.com' => 'yahoo.com',

        // Outlook typos
        'outlok.com' => 'outlook.com',
        'outllok.com' => 'outlook.com',
        'outlook.con' => 'outlook.com',
        'outlool.com' => 'outlook.com',

        // Live typos
        'live.con' => 'live.com',
        'liv.com' => 'live.com',

        // Otros comunes en Chile
        'gmail.cl' => 'gmail.com', // Gmail no tiene .cl
    ];

    /**
     * TLDs reconocidos. Los emails cuyo dominio NO termina en uno de estos
     * se consideran corruptos (caso real: `usuario@gmail.comsi0` pasa
     * filter_var pero el TLD `comsi0` no existe).
     */
    private const TLDS_VALIDOS = [
        // Genéricos comunes
        'com', 'org', 'net', 'edu', 'gov', 'info', 'biz', 'name', 'pro', 'me',
        'app', 'io', 'co', 'tv', 'live', 'tech', 'site', 'online', 'store',
        'xyz', 'club', 'shop', 'blog', 'cloud', 'email', 'systems', 'agency',

        // Latam
        'cl', 'ar', 'mx', 'pe', 'ec', 'uy', 'br', 've', 'py', 'bo', 'cr', 'gt',
        'hn', 'sv', 'ni', 'pa', 'do', 'cu', 'pr',

        // Iberia + UK + EU comunes
        'es', 'pt', 'uk', 'fr', 'de', 'it', 'nl', 'be', 'ch', 'se', 'no', 'fi',
        'dk', 'pl', 'ru',

        // Otros relevantes
        'us', 'ca', 'au', 'nz', 'jp', 'cn', 'in', 'za',
    ];

    /**
     * Patrones de error SMTP que indican email inválido permanente.
     */
    private const PATRONES_ERROR_PERMANENTE = [
        // Usuario no existe
        'user unknown',
        'user not found',
        'no such user',
        'unknown user',
        'mailbox not found',
        'recipient rejected',
        'address rejected',
        'invalid recipient',
        'undeliverable',

        // Dominio no existe
        'domain not found',
        'host not found',
        'no mx record',

        // Códigos SMTP de error permanente
        '550',  // Mailbox unavailable
        '551',  // User not local
        '552',  // Mailbox full (puede ser temporal, pero después de varios intentos...)
        '553',  // Mailbox name not allowed
        '554',  // Transaction failed
    ];

    /**
     * Valida un email y retorna si es válido con el motivo si no lo es.
     *
     * @return array{valid: bool, motivo: string|null, sugerencia: string|null}
     */
    public function validar(string $email): array
    {
        $email = trim(strtolower($email));

        // 1. Validar formato básico
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'valid' => false,
                'motivo' => 'formato_invalido',
                'sugerencia' => null,
            ];
        }

        // 2. Verificar dominios con typos conocidos
        $dominio = substr($email, strpos($email, '@') + 1);

        if (isset(self::DOMINIOS_TYPOS[$dominio])) {
            $dominioCorregido = self::DOMINIOS_TYPOS[$dominio];
            $emailCorregido = str_replace("@{$dominio}", "@{$dominioCorregido}", $email);

            return [
                'valid' => false,
                'motivo' => "dominio_typo:{$dominio}",
                'sugerencia' => $emailCorregido,
            ];
        }

        // 3. Verificar extensiones inválidas
        if (str_ends_with($dominio, '.con') || str_ends_with($dominio, '.cpm')) {
            return [
                'valid' => false,
                'motivo' => 'extension_invalida',
                'sugerencia' => str_replace(['.con', '.cpm'], '.com', $email),
            ];
        }

        // 4. Verificar TLD reconocido (detecta corrupciones como gmail.comsi0)
        $tld = strtolower(substr(strrchr($dominio, '.'), 1));
        if ($tld === '' || ! in_array($tld, self::TLDS_VALIDOS, true)) {
            return [
                'valid' => false,
                'motivo' => "tld_invalido:{$tld}",
                'sugerencia' => null,
            ];
        }

        // 5. Email parece válido
        return [
            'valid' => true,
            'motivo' => null,
            'sugerencia' => null,
        ];
    }

    /**
     * Determina si un error de envío indica que el email es permanentemente inválido.
     *
     * @param  string  $errorMessage  Mensaje de error del servidor SMTP
     * @return array{es_invalido: bool, motivo: string|null}
     */
    public function analizarErrorEnvio(string $errorMessage): array
    {
        $errorLower = strtolower($errorMessage);

        // Un error de base de datos (ej. unique violation de envios cuando un job concurrente
        // ya registró el envío) NO es un error SMTP. Antes caía acá y un dígito como '552' dentro
        // de 'SQLSTATE[23505]' o de un id lo malclasificaba como rebote permanente → marcaba el
        // email inválido en falso y disparaba el circuit breaker. Lo descartamos explícitamente.
        if (preg_match('/sqlstate|violates|constraint|duplicate key|deadlock|pdoexception|queryexception/i', $errorLower)) {
            return ['es_invalido' => false, 'motivo' => null];
        }

        foreach (self::PATRONES_ERROR_PERMANENTE as $patron) {
            // Códigos SMTP numéricos (550-554): match con límite de palabra para no pegar con
            // '552' embebido en números más grandes (ids, SQLSTATE, etc.). Patrones de texto:
            // substring como antes.
            $esCodigoNumerico = ctype_digit($patron);
            $match = $esCodigoNumerico
                ? (bool) preg_match('/\b'.preg_quote($patron, '/').'\b/', $errorLower)
                : str_contains($errorLower, strtolower($patron));

            if ($match) {
                return [
                    'es_invalido' => true,
                    'motivo' => "smtp_error:{$patron}",
                ];
            }
        }

        return [
            'es_invalido' => false,
            'motivo' => null,
        ];
    }

    /**
     * Procesa un error de envío y marca el prospecto si el email es inválido.
     *
     * @return bool True si se marcó como inválido
     */
    public function procesarErrorEnvio(Prospecto $prospecto, string $errorMessage): bool
    {
        $analisis = $this->analizarErrorEnvio($errorMessage);

        if ($analisis['es_invalido']) {
            $prospecto->marcarEmailInvalido($analisis['motivo']);

            Log::info('EmailValidationService: Email marcado como inválido por error de envío', [
                'prospecto_id' => $prospecto->id,
                'email' => $prospecto->email,
                'motivo' => $analisis['motivo'],
                'error_original' => substr($errorMessage, 0, 200),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Valida emails de prospectos en batch y marca los inválidos.
     * Útil para limpiar la base de datos existente.
     *
     * @param  int  $batchSize  Tamaño del batch
     * @param  callable|null  $progressCallback  Callback para reportar progreso
     * @return array{total: int, invalidos: int, sugerencias: array}
     */
    public function limpiarEmailsInvalidos(int $batchSize = 1000, ?callable $progressCallback = null): array
    {
        $resultado = [
            'total' => 0,
            'invalidos' => 0,
            'sugerencias' => [],
        ];

        Prospecto::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(function ($q) {
                $q->where('email_invalido', false)
                    ->orWhereNull('email_invalido');
            })
            ->chunkById($batchSize, function ($prospectos) use (&$resultado, $progressCallback) {
                foreach ($prospectos as $prospecto) {
                    $resultado['total']++;

                    $validacion = $this->validar($prospecto->email);

                    if (! $validacion['valid']) {
                        $prospecto->marcarEmailInvalido($validacion['motivo']);
                        $resultado['invalidos']++;

                        if ($validacion['sugerencia']) {
                            $resultado['sugerencias'][] = [
                                'prospecto_id' => $prospecto->id,
                                'email_actual' => $prospecto->email,
                                'sugerencia' => $validacion['sugerencia'],
                                'motivo' => $validacion['motivo'],
                            ];
                        }
                    }
                }

                if ($progressCallback) {
                    $progressCallback($resultado['total'], $resultado['invalidos']);
                }
            });

        return $resultado;
    }

    /**
     * Obtiene estadísticas de calidad de emails por origen de importación.
     */
    public function obtenerEstadisticasCalidad(): array
    {
        return Prospecto::query()
            ->selectRaw("
                importaciones.origen,
                COUNT(*) as total_prospectos,
                SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as con_email,
                SUM(CASE WHEN email_invalido = true THEN 1 ELSE 0 END) as emails_invalidos,
                SUM(CASE WHEN estado = 'desuscrito' THEN 1 ELSE 0 END) as desuscritos
            ")
            ->join('importaciones', 'prospectos.importacion_id', '=', 'importaciones.id')
            ->groupBy('importaciones.origen')
            ->get()
            ->map(function ($row) {
                $conEmail = $row->con_email ?? 0;
                $invalidos = $row->emails_invalidos ?? 0;

                return [
                    'origen' => $row->origen,
                    'total_prospectos' => $row->total_prospectos,
                    'con_email' => $conEmail,
                    'sin_email' => $row->total_prospectos - $conEmail,
                    'emails_invalidos' => $invalidos,
                    'emails_validos' => $conEmail - $invalidos,
                    'desuscritos' => $row->desuscritos ?? 0,
                    'tasa_validez' => $conEmail > 0
                        ? round((($conEmail - $invalidos) / $conEmail) * 100, 2)
                        : 0,
                ];
            })
            ->toArray();
    }

    /**
     * Obtiene los motivos de invalidez más comunes.
     */
    public function obtenerMotivosComunes(int $limit = 10): array
    {
        return Prospecto::query()
            ->selectRaw('email_invalido_motivo, COUNT(*) as cantidad')
            ->where('email_invalido', true)
            ->whereNotNull('email_invalido_motivo')
            ->groupBy('email_invalido_motivo')
            ->orderByDesc('cantidad')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
