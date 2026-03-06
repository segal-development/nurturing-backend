<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lote>
 */
class LoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'LOTE_'.strtoupper(fake()->lexify('????')),
            'user_id' => \App\Models\User::factory(),
            'estado' => 'abierto',
            'total_archivos' => 0,
            'total_registros' => 0,
            'registros_exitosos' => 0,
            'registros_fallidos' => 0,
        ];
    }

    /**
     * Lote cerrado.
     */
    public function cerrado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'cerrado',
        ]);
    }

    /**
     * Lote de Sysgal.
     */
    public function sysgal(): static
    {
        return $this->state(fn (array $attributes) => [
            'nombre' => 'SYSGAL',
            'clasificacion_value' => 'global',
        ]);
    }
}
