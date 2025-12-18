<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProspectoEnFlujo>
 */
class ProspectoEnFlujoFactory extends Factory
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
            'canal_asignado' => fake()->randomElement(['email', 'sms']),
            'estado' => 'pendiente',
            'etapa_actual_id' => null,
            'fecha_inicio' => now(),
            'fecha_proxima_etapa' => null,
            'completado' => false,
            'cancelado' => false,
        ];
    }

    public function porEmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'canal_asignado' => 'email',
        ]);
    }

    public function porSms(): static
    {
        return $this->state(fn (array $attributes) => [
            'canal_asignado' => 'sms',
        ]);
    }

    public function pendiente(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'pendiente',
        ]);
    }

    public function enProceso(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'en_proceso',
        ]);
    }

    public function completado(): static
    {
        return $this->state(fn (array $attributes) => [
            'completado' => true,
            'fecha_proxima_etapa' => null,
        ]);
    }

    public function cancelado(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancelado' => true,
            'fecha_proxima_etapa' => null,
        ]);
    }
}
