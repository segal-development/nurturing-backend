<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlantillaMensaje>
 */
class PlantillaMensajeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Plantilla '.fake()->word(),
            'asunto' => fake()->sentence(),
            'contenido' => 'Hola {nombre}, tu deuda es de {monto_deuda}.',
            'tipo_canal' => fake()->randomElement(['email', 'sms', 'whatsapp']),
            'variables_disponibles' => ['nombre', 'email', 'monto_deuda', 'origen'],
            'activo' => true,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'activo' => false,
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'tipo_canal' => 'email',
        ]);
    }

    public function sms(): static
    {
        return $this->state(fn (array $attributes) => [
            'tipo_canal' => 'sms',
            'asunto' => null,
        ]);
    }

    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'tipo_canal' => 'whatsapp',
            'asunto' => null,
        ]);
    }
}
