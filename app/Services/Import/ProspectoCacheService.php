<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de cache para prospectos existentes.
 * Carga emails y teléfonos en memoria para búsqueda O(1).
 * 
 * Single Responsibility: Solo maneja el cache de identificadores de prospectos.
 */
final class ProspectoCacheService
{
    /** @var array<string, int> email => prospecto_id */
    private array $emailIndex = [];
    
    /** @var array<string, int> telefono => prospecto_id */
    private array $telefonoIndex = [];
    
    private bool $loaded = false;

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
        
        $this->loadEmails();
        $this->loadTelefonos();
        
        $this->loaded = true;
        
        $elapsed = round(microtime(true) - $startTime, 2);
        
        Log::info('ProspectoCacheService: Cache cargado', [
            'emails_count' => count($this->emailIndex),
            'telefonos_count' => count($this->telefonoIndex),
            'tiempo_segundos' => $elapsed,
            'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    /**
     * Busca un prospecto existente por email o teléfono.
     * Complejidad: O(1)
     * 
     * @return int|null ID del prospecto o null si no existe
     */
    public function findExistingProspectoId(?string $email, ?string $telefono): ?int
    {
        // Prioridad: email > teléfono
        if ($email !== null && isset($this->emailIndex[$email])) {
            return $this->emailIndex[$email];
        }

        if ($telefono !== null && isset($this->telefonoIndex[$telefono])) {
            return $this->telefonoIndex[$telefono];
        }

        return null;
    }

    /**
     * Registra un nuevo prospecto en el cache.
     * Usado para detectar duplicados dentro del mismo archivo.
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

    /**
     * Verifica si un email ya existe (en BD o pendiente de crear).
     */
    public function emailExists(?string $email): bool
    {
        return $email !== null && isset($this->emailIndex[$email]);
    }

    /**
     * Verifica si un teléfono ya existe (en BD o pendiente de crear).
     */
    public function telefonoExists(?string $telefono): bool
    {
        return $telefono !== null && isset($this->telefonoIndex[$telefono]);
    }

    private function loadEmails(): void
    {
        $this->emailIndex = DB::table('prospectos')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('id', 'email')
            ->toArray();
    }

    private function loadTelefonos(): void
    {
        $this->telefonoIndex = DB::table('prospectos')
            ->whereNotNull('telefono')
            ->where('telefono', '!=', '')
            ->pluck('id', 'telefono')
            ->toArray();
    }

    public function getStats(): array
    {
        return [
            'emails_count' => count($this->emailIndex),
            'telefonos_count' => count($this->telefonoIndex),
            'loaded' => $this->loaded,
        ];
    }
}
