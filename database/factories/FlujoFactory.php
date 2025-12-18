<?php

namespace Database\Factories;

use App\Models\TipoProspecto;
use Illuminate\Database\Eloquent\Factories\Factory;

class FlujoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tipo_prospecto_id' => TipoProspecto::factory(),
            'user_id' => \App\Models\User::factory(),
            'origen' => fake()->randomElement(['banco_x', 'campania_verano', 'base_general', 'referidos']),
            'nombre' => 'Flujo '.fake()->word(),
            'descripcion' => fake()->sentence(),
            'canal_envio' => fake()->randomElement(['email', 'sms', 'ambos']),
            'activo' => true,
            'metadata' => null,
        ];
    }

    public function porEmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'canal_envio' => 'email',
        ]);
    }

    public function porSms(): static
    {
        return $this->state(fn (array $attributes) => [
            'canal_envio' => 'sms',
        ]);
    }

    public function porAmbos(): static
    {
        return $this->state(fn (array $attributes) => [
            'canal_envio' => 'ambos',
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'activo' => false,
        ]);
    }
}
