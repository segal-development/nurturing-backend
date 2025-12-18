<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EtapaFlujo>
 */
class EtapaFlujoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flujo_id' => \App\Models\Flujo::factory(),
            'nombre' => 'Etapa '.fake()->word(),
            'dias_desde_inicio' => fake()->randomElement([15, 30, 45, 60, 90, 150, 365]),
            'orden' => fake()->numberBetween(1, 10),
            'plantilla_mensaje_id' => \App\Models\PlantillaMensaje::factory(),
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'activo' => false,
        ]);
    }
}
