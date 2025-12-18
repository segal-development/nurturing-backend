<?php

namespace Database\Factories;

use App\Models\TipoProspecto;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProspectoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tipo_prospecto_id' => TipoProspecto::factory(),
            'nombre' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'telefono' => '+569'.fake()->numerify('########'),
            'estado' => 'activo',
            'monto_deuda' => fake()->randomFloat(2, 0, 500000),
            'fecha_ultimo_contacto' => fake()->dateTimeBetween('-1 year', 'now'),
            'metadata' => null,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'inactivo',
        ]);
    }

    public function convertido(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'convertido',
        ]);
    }
}
