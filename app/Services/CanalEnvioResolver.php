<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CanalEnvio;

/**
 * Service responsable de resolver/inferir el canal de envío de un flujo.
 * 
 * Aplica Single Responsibility Principle: solo se encarga de determinar
 * el canal basándose en las etapas del flujo.
 */
final class CanalEnvioResolver
{
    private const VALID_TIPOS_MENSAJE = ['email', 'sms'];

    /**
     * Infiere el canal de envío basándose en los tipos de mensaje de las etapas.
     *
     * @param array<int, array{tipo_mensaje?: string}> $stages Las etapas del flujo
     * @return CanalEnvio El canal inferido
     */
    public function resolveFromStages(array $stages): CanalEnvio
    {
        $tiposMensaje = $this->extractTiposMensaje($stages);

        if ($this->isEmpty($tiposMensaje)) {
            return CanalEnvio::EMAIL; // Default cuando no hay etapas
        }

        $uniqueTipos = $this->getUniqueTipos($tiposMensaje);

        return $this->determineCanal($uniqueTipos);
    }

    /**
     * Infiere el canal desde la estructura completa del FlowBuilder.
     *
     * @param array{stages?: array<int, array{tipo_mensaje?: string}>} $structure
     * @return CanalEnvio
     */
    public function resolveFromStructure(array $structure): CanalEnvio
    {
        $stages = $structure['stages'] ?? [];

        return $this->resolveFromStages($stages);
    }

    /**
     * Valida si un tipo de mensaje es válido.
     *
     * @param string $tipoMensaje
     * @return bool
     */
    public function isValidTipoMensaje(string $tipoMensaje): bool
    {
        return in_array(strtolower($tipoMensaje), self::VALID_TIPOS_MENSAJE, true);
    }

    /**
     * Extrae los tipos de mensaje de las etapas.
     *
     * @param array<int, array{tipo_mensaje?: string}> $stages
     * @return array<int, string>
     */
    private function extractTiposMensaje(array $stages): array
    {
        $tiposMensaje = [];

        foreach ($stages as $stage) {
            $tipo = $this->extractTipoFromStage($stage);

            if ($tipo === null) {
                continue;
            }

            $tiposMensaje[] = $tipo;
        }

        return $tiposMensaje;
    }

    /**
     * Extrae el tipo de mensaje de una etapa individual.
     *
     * @param array{tipo_mensaje?: string} $stage
     * @return string|null
     */
    private function extractTipoFromStage(array $stage): ?string
    {
        $tipo = $stage['tipo_mensaje'] ?? null;

        if ($tipo === null) {
            return null;
        }

        $tipoNormalized = strtolower(trim($tipo));

        if (!$this->isValidTipoMensaje($tipoNormalized)) {
            return null;
        }

        return $tipoNormalized;
    }

    /**
     * Obtiene los tipos únicos de mensaje.
     *
     * @param array<int, string> $tiposMensaje
     * @return array<int, string>
     */
    private function getUniqueTipos(array $tiposMensaje): array
    {
        return array_values(array_unique($tiposMensaje));
    }

    /**
     * Determina el canal basándose en los tipos únicos encontrados.
     *
     * @param array<int, string> $uniqueTipos
     * @return CanalEnvio
     */
    private function determineCanal(array $uniqueTipos): CanalEnvio
    {
        $tiposCount = count($uniqueTipos);

        // Ningún tipo válido encontrado
        if ($tiposCount === 0) {
            return CanalEnvio::EMAIL;
        }

        // Un solo tipo: retornar ese canal específico
        if ($tiposCount === 1) {
            return CanalEnvio::fromTipoMensaje($uniqueTipos[0]);
        }

        // Múltiples tipos: canal mixto
        return CanalEnvio::AMBOS;
    }

    /**
     * Helper para verificar si el array está vacío.
     *
     * @param array<int, string> $items
     * @return bool
     */
    private function isEmpty(array $items): bool
    {
        return count($items) === 0;
    }
}
