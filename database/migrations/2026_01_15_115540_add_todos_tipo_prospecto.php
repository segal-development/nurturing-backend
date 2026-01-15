<?php

use App\Models\TipoProspecto;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Nombre del tipo especial para seleccionar todos los prospectos.
     */
    private const TIPO_TODOS_NOMBRE = 'Todos';

    /**
     * Run the migrations.
     *
     * Agrega el tipo de prospecto "Todos" que permite crear flujos
     * sin filtrar por tipo de deuda específico.
     */
    public function up(): void
    {
        // Solo crear si no existe (idempotente)
        if (TipoProspecto::where('nombre', self::TIPO_TODOS_NOMBRE)->exists()) {
            return;
        }

        TipoProspecto::create([
            'nombre' => self::TIPO_TODOS_NOMBRE,
            'descripcion' => 'Incluye todos los prospectos sin distinción de tipo de deuda',
            'monto_min' => null,
            'monto_max' => null,
            'orden' => 0, // Primero en la lista
            'activo' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * IMPORTANTE: Solo elimina si no hay flujos usando este tipo.
     * Si hay flujos, lanza excepción para evitar data corruption.
     */
    public function down(): void
    {
        $tipoTodos = TipoProspecto::where('nombre', self::TIPO_TODOS_NOMBRE)->first();

        if ($tipoTodos === null) {
            return;
        }

        // Verificar que no hay flujos usando este tipo
        $flujosConTipo = $tipoTodos->flujos()->count();

        if ($flujosConTipo > 0) {
            throw new \RuntimeException(
                "No se puede eliminar el tipo '{$tipoTodos->nombre}' porque hay {$flujosConTipo} flujos usándolo. " .
                'Reasigna los flujos a otro tipo antes de hacer rollback.'
            );
        }

        $tipoTodos->delete();
    }
};
