<?php

namespace Database\Factories;

use App\Models\FlujoEjecucion;
use App\Models\FlujoEjecucionEtapa;
use Illuminate\Database\Eloquent\Factories\Factory;

class FlujoEjecucionEtapaFactory extends Factory
{
    protected $model = FlujoEjecucionEtapa::class;

    public function definition(): array
    {
        return [
            'flujo_ejecucion_id' => FlujoEjecucion::factory(),
            'node_id' => 'stage-'.$this->faker->uuid(),
            'fecha_programada' => now(),
            'estado' => 'pending',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'pending',
        ]);
    }

    public function executing(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'executing',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'completed',
            'fecha_ejecucion' => now(),
            'message_id' => $this->faker->randomNumber(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'failed',
            'error_mensaje' => 'Error en el envío',
        ]);
    }
}
