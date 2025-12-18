<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TipoProspectoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->word(),
            'descripcion' => fake()->sentence(),
            'monto_min' => fake()->randomFloat(2, 0, 100000),
            'monto_max' => fake()->randomFloat(2, 100000, 500000),
            'orden' => fake()->numberBetween(1, 10),
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
