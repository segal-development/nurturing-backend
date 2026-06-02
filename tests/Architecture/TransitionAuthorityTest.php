<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fitness function: enforce that state='completed' writes on FlujoEjecucion and
 * ultima_etapa_node_id mutations on ProspectoEnFlujo only happen inside the
 * authorized classes (GuardedTransition + the physical writer FlujoEjecucion model).
 *
 * Pattern: same grep-based approach as ProspectoEnFlujoInsertTest.
 *
 * WHITELIST:
 *   - app/Services/GuardedTransition.php  — the authority
 *   - app/Models/FlujoEjecucion.php       — physical writer (finalizarRespetandoPerpetuo)
 *
 * PROHIBITED PATTERNS (outside whitelist):
 *   1. 'estado' => 'completed'  in update() / array context targeting FlujoEjecucion
 *   2. ->ultima_etapa_node_id = ... (property assignment)
 *   3. 'ultima_etapa_node_id' => ... inside update( or array context
 *   4. ->finalizarRespetandoPerpetuo( called outside the guard
 *
 * NOTE: This test is expected to be RED in PR-1 because the ~7 legacy writers
 * have not yet been migrated (that is Fase 2 / PR-2). The test IS correctly
 * detecting the existing violations. What must be green in PR-1 is that the
 * test itself runs without errors and correctly identifies violations.
 *
 * The sdd-verify phase will confirm green status after PR-2 is merged.
 */
class TransitionAuthorityTest extends TestCase
{
    /**
     * Patterns that indicate writing completed/posicion outside the authority.
     *
     * Each entry: [regex, human-readable description]
     */
    private const PATTERNS = [
        // 'estado' => 'completed' inside an update/array call
        ["/['\"]estado['\"]\s*=>\s*['\"]completed['\"]/", "escribir 'estado'=>'completed' directamente"],
        // ->ultima_etapa_node_id = value  (property assignment)
        ["/->ultima_etapa_node_id\s*=/", "asignar ->ultima_etapa_node_id directamente"],
        // 'ultima_etapa_node_id' => ... inside update(
        ["/['\"]ultima_etapa_node_id['\"]\s*=>/", "escribir 'ultima_etapa_node_id' en update/array directamente"],
        // ->finalizarRespetandoPerpetuo(  call outside guard
        ["/->finalizarRespetandoPerpetuo\s*\(/", "llamar ->finalizarRespetandoPerpetuo() directamente"],
    ];

    /**
     * Files that are allowed to contain these patterns (the authority + physical writer).
     */
    private array $whitelistPaths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whitelistPaths = array_filter([
            realpath(__DIR__ . '/../../app/Services/GuardedTransition.php'),
            realpath(__DIR__ . '/../../app/Models/FlujoEjecucion.php'),
            realpath(__DIR__ . '/../../app/Models/ProspectoEnFlujo.php'), // crearBatch sets ultima_etapa
            // BackfillUltimaEtapaCommand is a one-time repair command that derives
            // ultima_etapa_node_id from historical envios (not from flow logic).
            // Its use of the column is a legitimate data-repair backfill, not a
            // transition decision. Whitelisted per design decision (Fase 2 PR-2).
            realpath(__DIR__ . '/../../app/Console/Commands/BackfillUltimaEtapaCommand.php'),
        ]);
    }

    #[Test]
    public function solo_la_autoridad_puede_escribir_estado_completed_y_ultima_etapa_node_id(): void
    {
        $appDir = realpath(__DIR__ . '/../../app');
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();

            // Skip whitelisted files
            if (in_array($filePath, $this->whitelistPaths, true)) {
                continue;
            }

            $lines = file($filePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $lineNumber => $lineContent) {
                // Skip pure single-line comments
                $trimmed = ltrim($lineContent);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                // Skip lines explicitly suppressed with @transition-authority-ok
                // Use this ONLY for writes to FlujoEjecucionEtapa or FlujoJob (NOT FlujoEjecucion).
                // Example: FlujoEjecucionEtapa::update(['estado' => 'completed']) is legitimate —
                // only FlujoEjecucion::estado and ProspectoEnFlujo::ultima_etapa_node_id are guarded.
                if (str_contains($lineContent, '@transition-authority-ok')) {
                    continue;
                }

                foreach (self::PATTERNS as [$pattern, $description]) {
                    if (preg_match($pattern, $lineContent)) {
                        $relativePath = str_replace(
                            realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR,
                            '',
                            $filePath
                        );
                        $violations[] = sprintf(
                            'Escritura no autorizada (%s) en %s:%d. Usá GuardedTransition.',
                            $description,
                            $relativePath,
                            $lineNumber + 1
                        );
                        break; // one violation per line is enough
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Se encontraron escrituras de completed/ultima_etapa_node_id fuera de la autoridad GuardedTransition:\n\n"
            . implode("\n", $violations)
            . "\n\nTodas las escrituras de estado='completed' en FlujoEjecucion y ultima_etapa_node_id en ProspectoEnFlujo "
            . "DEBEN pasar por GuardedTransition."
        );
    }
}
