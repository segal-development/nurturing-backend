<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fitness function: enforce the canonical insert point for prospecto_en_flujo.
 *
 * This test FAILS if any file under app/ performs a direct insert to the
 * prospecto_en_flujo table outside of ProspectoEnFlujo::crearBatch().
 *
 * Whitelisted: app/Models/ProspectoEnFlujo.php (the canonical method lives there).
 *
 * False-positive mitigation: the regex targets method-call syntax
 * (e.g. `->insert(`, `::insert(`), which virtually never appears in comments.
 * If a comment happens to trigger it, rewrite the comment — the signal is high
 * and the cost is low.
 */
class ProspectoEnFlujoInsertTest extends TestCase
{
    /**
     * Patterns that indicate a direct insert to prospecto_en_flujo outside crearBatch.
     */
    private const PATTERNS = [
        // DB::table('prospecto_en_flujo')->insert or ->insertOrIgnore
        "/DB::table\(['\"]prospecto_en_flujo['\"]\)\s*->\s*(insert|insertOrIgnore)\s*\(/",
        // ProspectoEnFlujo::insert( or ::insertOrIgnore(
        "/ProspectoEnFlujo::(insert|insertOrIgnore)\s*\(/",
        // ->prospectosEnFlujo()->create( — Eloquent relation direct create
        "/->prospectosEnFlujo\(\)\s*->\s*create\s*\(/",
    ];

    /**
     * The only file allowed to contain direct insert logic for prospecto_en_flujo.
     * Normalised to realpath for reliable comparison.
     */
    private string $whitelistPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whitelistPath = realpath(
            __DIR__ . '/../../app/Models/ProspectoEnFlujo.php'
        );
    }

    #[Test]
    public function no_hay_inserts_directos_a_prospecto_en_flujo_fuera_del_metodo_canonico(): void
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

            // Skip the whitelisted canonical file
            if ($filePath === $this->whitelistPath) {
                continue;
            }

            $lines = file($filePath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $lineNumber => $lineContent) {
                // Skip lines that are pure single-line comments (// or #)
                $trimmed = ltrim($lineContent);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                foreach (self::PATTERNS as $pattern) {
                    if (preg_match($pattern, $lineContent)) {
                        $relativePath = str_replace(
                            realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR,
                            '',
                            $filePath
                        );
                        $violations[] = sprintf(
                            'Insert directo a prospecto_en_flujo en %s:%d. Usá ProspectoEnFlujo::crearBatch().',
                            $relativePath,
                            $lineNumber + 1 // file() is 0-indexed
                        );
                        break; // one violation per line is enough
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Se encontraron inserts directos a prospecto_en_flujo fuera del método canónico crearBatch:\n\n"
            . implode("\n", $violations)
            . "\n\nTodos los inserts a prospecto_en_flujo DEBEN pasar por ProspectoEnFlujo::crearBatch()."
        );
    }
}
