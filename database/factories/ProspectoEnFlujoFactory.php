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
            'fecha_ingreso' => null,
            'completado' => false,
            'cancelado' => false,
        ];
    }

    /**
     * State para asignar una fecha_ingreso específica (o today si no se pasa).
     */
    public function conFechaIngreso(?string $fecha = null): static
    {
        return $this->state(fn (array $attributes) => [
            'fecha_ingreso' => $fecha ?? now()->toDateString(),
        ]);
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
