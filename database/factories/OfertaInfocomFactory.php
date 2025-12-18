<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OfertaInfocom>
 */
class OfertaInfocomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Oferta '.fake()->word(),
            'descripcion' => fake()->sentence(),
            'contenido' => fake()->paragraph(),
            'fecha_inicio' => now()->subDays(10),
            'fecha_fin' => now()->addDays(20),
            'activo' => true,
            'metadata' => null,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'activo' => false,
        ]);
    }

    public function vencido(): static
    {
        return $this->state(fn (array $attributes) => [
            'fecha_inicio' => now()->subDays(60),
            'fecha_fin' => now()->subDays(10),
        ]);
    }

    public function futuro(): static
    {
        return $this->state(fn (array $attributes) => [
            'fecha_inicio' => now()->addDays(10),
            'fecha_fin' => now()->addDays(30),
        ]);
    }
}
