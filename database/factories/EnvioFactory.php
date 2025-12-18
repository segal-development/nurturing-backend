<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Envio>
 */
class EnvioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prospecto_id' => \App\Models\Prospecto::factory(),
            'flujo_id' => \App\Models\Flujo::factory(),
            'etapa_flujo_id' => \App\Models\EtapaFlujo::factory(),
            'plantilla_mensaje_id' => \App\Models\PlantillaMensaje::factory(),
            'prospecto_en_flujo_id' => \App\Models\ProspectoEnFlujo::factory(),
            'asunto' => fake()->sentence(),
            'contenido_enviado' => fake()->paragraph(),
            'canal' => fake()->randomElement(['email', 'sms', 'whatsapp']),
            'destinatario' => fake()->email(),
            'estado' => 'pendiente',
            'fecha_programada' => now()->addDays(1),
            'fecha_enviado' => null,
            'fecha_abierto' => null,
            'fecha_clickeado' => null,
            'metadata' => null,
        ];
    }

    public function enviado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'enviado',
            'fecha_enviado' => now(),
        ]);
    }

    public function fallido(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'fallido',
            'metadata' => ['error' => 'Test error'],
        ]);
    }

    public function abierto(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'abierto',
            'fecha_enviado' => now()->subDays(1),
            'fecha_abierto' => now(),
        ]);
    }

    public function clickeado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'clickeado',
            'fecha_enviado' => now()->subDays(2),
            'fecha_abierto' => now()->subDays(1),
            'fecha_clickeado' => now(),
        ]);
    }
}
