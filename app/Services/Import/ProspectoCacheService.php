<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para verificar prospectos existentes.
 * 
 * VERSIÓN 2: Ya NO carga todo en memoria.
 * Usa queries directas a la BD con índices.
 * 
 * Esto es más lento por registro pero NO consume memoria proporcional
 * a la cantidad de prospectos existentes.
 */
final class ProspectoCacheService
{
    /** 
     * Cache local solo para el archivo actual (detectar duplicados dentro del mismo archivo)
     * @var array<string, bool> 
     */
    private array $emailsEnArchivo = [];
    
    /** @var array<string, bool> */
    private array $telefonosEnArchivo = [];
    
    private bool $loaded = false;

    /**
     * "Carga" inicial - ahora solo marca como listo.
     * Ya no cargamos todos los prospectos en memoria.
     */
    public function loadExistingProspectos(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        
        // Contar para estadísticas
        $emailCount = DB::table('prospectos')->whereNotNull('email')->count();
        $telefonoCount = DB::table('prospectos')->whereNotNull('telefono')->count();
        
        Log::info('ProspectoCacheService: Iniciado (modo query directo)', [
            'prospectos_con_email' => $emailCount,
            'prospectos_con_telefono' => $telefonoCount,
            'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    /**
     * Busca un prospecto existente por email o teléfono.
     * Ahora usa queries directas en lugar de cache en memoria.
     * 
     * @return int|null ID del prospecto o null si no existe
     */
    public function findExistingProspectoId(?string $email, ?string $telefono): ?int
    {
        // Primero verificar duplicados dentro del mismo archivo
        if ($email !== null && isset($this->emailsEnArchivo[$email])) {
            return -1; // Indicar que es duplicado (ya está pendiente de insertar)
        }
        
        if ($telefono !== null && isset($this->telefonosEnArchivo[$telefono])) {
            return -1;
        }

        // Buscar en BD por email
        if ($email !== null) {
            $existing = DB::table('prospectos')
                ->where('email', $email)
                ->value('id');
            
            if ($existing) {
                return (int) $existing;
            }
        }

        // Buscar en BD por teléfono
        if ($telefono !== null) {
            $existing = DB::table('prospectos')
                ->where('telefono', $telefono)
                ->value('id');
            
            if ($existing) {
                return (int) $existing;
            }
        }

        return null;
    }

    /**
     * Registra un nuevo prospecto en el cache del archivo actual.
     * Solo para detectar duplicados dentro del mismo archivo.
     */
    public function registerNewProspecto(?string $email, ?string $telefono, int $id = -1): void
    {
        if ($email !== null) {
            $this->emailsEnArchivo[$email] = true;
        }
        
        if ($telefono !== null) {
            $this->telefonosEnArchivo[$telefono] = true;
        }
    }

    /**
     * Verifica si un email ya existe (en BD o pendiente de crear en este archivo).
     */
    public function emailExists(?string $email): bool
    {
        if ($email === null) {
            return false;
        }
        
        // Duplicado dentro del archivo
        if (isset($this->emailsEnArchivo[$email])) {
            return true;
        }
        
        // Existe en BD
        return DB::table('prospectos')->where('email', $email)->exists();
    }

    /**
     * Verifica si un teléfono ya existe (en BD o pendiente de crear en este archivo).
     */
    public function telefonoExists(?string $telefono): bool
    {
        if ($telefono === null) {
            return false;
        }
        
        // Duplicado dentro del archivo
        if (isset($this->telefonosEnArchivo[$telefono])) {
            return true;
        }
        
        // Existe en BD
        return DB::table('prospectos')->where('telefono', $telefono)->exists();
    }

    public function getStats(): array
    {
        return [
            'emails_en_archivo' => count($this->emailsEnArchivo),
            'telefonos_en_archivo' => count($this->telefonosEnArchivo),
            'loaded' => $this->loaded,
            'modo' => 'query_directo',
        ];
    }

    /**
     * Limpia la cache del archivo actual.
     */
    public function clear(): void
    {
        $emailCount = count($this->emailsEnArchivo);
        $telefonoCount = count($this->telefonosEnArchivo);
        
        $this->emailsEnArchivo = [];
        $this->telefonosEnArchivo = [];
        $this->loaded = false;
        
        Log::info('ProspectoCacheService: Cache de archivo limpiado', [
            'emails_liberados' => $emailCount,
            'telefonos_liberados' => $telefonoCount,
            'memoria_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }
}
