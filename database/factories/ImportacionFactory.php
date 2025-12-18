<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Importacion>
 */
class ImportacionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'nombre_archivo' => fake()->word().'.xlsx',
            'ruta_archivo' => 'importaciones/'.fake()->word().'.xlsx',
            'origen' => fake()->randomElement(['banco_x', 'campania_verano', 'base_general', 'referidos']),
            'total_registros' => fake()->numberBetween(10, 1000),
            'registros_exitosos' => fake()->numberBetween(5, 900),
            'registros_fallidos' => fake()->numberBetween(0, 100),
            'estado' => 'completado',
            'fecha_importacion' => now(),
            'metadata' => null,
        ];
    }

    public function procesando(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'procesando',
        ]);
    }

    public function fallido(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'fallido',
        ]);
    }
}
