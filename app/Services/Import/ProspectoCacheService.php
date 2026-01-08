<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de cache para prospectos existentes.
 * 
 * Carga emails y teléfonos en memoria para búsqueda O(1).
 * Esencial para performance en importaciones grandes (100k+ registros).
 * 
 * @example
 * $cache = new ProspectoCacheService();
 * $cache->loadExistingProspectos();
 * $existingId = $cache->findExistingProspectoId($email, $telefono);
 */
final class ProspectoCacheService
{
    // =========================================================================
    // ESTADO
    // =========================================================================

    /** @var array<string, int> email => prospecto_id */
    private array $emailIndex = [];
    
    /** @var array<string, int> telefono => prospecto_id */
    private array $telefonoIndex = [];
    
    private bool $loaded = false;

    // =========================================================================
    // CARGA DE DATOS
    // =========================================================================

    /**
     * Carga todos los emails y teléfonos existentes en memoria.
     * Debe llamarse una vez antes de procesar el archivo.
     */
    public function loadExistingProspectos(): void
    {
        if ($this->loaded) {
            return;
        }

        $startTime = microtime(true);
        
        $this->loadEmailIndex();
        $this->loadTelefonoIndex();
        
        $this->loaded = true;
        
        $this->logCacheLoaded($startTime);
    }

    private function loadEmailIndex(): void
    {
        $this->emailIndex = DB::table('prospectos')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('id', 'email')
            ->toArray();
    }

    private function loadTelefonoIndex(): void
    {
        $this->telefonoIndex = DB::table('prospectos')
            ->whereNotNull('telefono')
            ->where('telefono', '!=', '')
            ->pluck('id', 'telefono')
            ->toArray();
    }

    private function logCacheLoaded(float $startTime): void
    {
        Log::info('ProspectoCacheService: Cache cargado', [
            'emails_count' => count($this->emailIndex),
            'telefonos_count' => count($this->telefonoIndex),
            'tiempo_segundos' => round(microtime(true) - $startTime, 2),
            'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    // =========================================================================
    // BÚSQUEDA
    // =========================================================================

    /**
     * Busca un prospecto existente por email o teléfono.
     * 
     * Prioridad: email > teléfono
     * Complejidad: O(1)
     * 
     * @return int|null ID del prospecto o null si no existe
     */
    public function findExistingProspectoId(?string $email, ?string $telefono): ?int
    {
        return $this->findByEmail($email) ?? $this->findByTelefono($telefono);
    }

    private function findByEmail(?string $email): ?int
    {
        if ($email === null) {
            return null;
        }
        
        return $this->emailIndex[$email] ?? null;
    }

    private function findByTelefono(?string $telefono): ?int
    {
        if ($telefono === null) {
            return null;
        }
        
        return $this->telefonoIndex[$telefono] ?? null;
    }

    // =========================================================================
    // REGISTRO DE NUEVOS
    // =========================================================================

    /**
     * Registra un nuevo prospecto en el cache.
     * Usado para detectar duplicados dentro del mismo archivo.
     * 
     * @param int $id ID del prospecto (-1 para pendientes de crear)
     */
    public function registerNewProspecto(?string $email, ?string $telefono, int $id = -1): void
    {
        if ($email !== null) {
            $this->emailIndex[$email] = $id;
        }
        
        if ($telefono !== null) {
            $this->telefonoIndex[$telefono] = $id;
        }
    }

    // =========================================================================
    // CONSULTAS
    // =========================================================================

    /**
     * Verifica si un email ya existe.
     */
    public function emailExists(?string $email): bool
    {
        return $email !== null && isset($this->emailIndex[$email]);
    }

    /**
     * Verifica si un teléfono ya existe.
     */
    public function telefonoExists(?string $telefono): bool
    {
        return $telefono !== null && isset($this->telefonoIndex[$telefono]);
    }

    /**
     * Verifica si el cache está cargado.
     */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    // =========================================================================
    // ESTADÍSTICAS
    // =========================================================================

    public function getStats(): array
    {
        return [
            'emails_count' => count($this->emailIndex),
            'telefonos_count' => count($this->telefonoIndex),
            'loaded' => $this->loaded,
        ];
    }

    public function getEmailCount(): int
    {
        return count($this->emailIndex);
    }

    public function getTelefonoCount(): int
    {
        return count($this->telefonoIndex);
    }
}
