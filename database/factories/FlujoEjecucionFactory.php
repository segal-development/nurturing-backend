<?php

namespace Database\Factories;

use App\Models\Flujo;
use App\Models\FlujoEjecucion;
use Illuminate\Database\Eloquent\Factories\Factory;

class FlujoEjecucionFactory extends Factory
{
    protected $model = FlujoEjecucion::class;

    public function definition(): array
    {
        return [
            'flujo_id' => Flujo::factory(),
            'origen_id' => 'test-origen-'.$this->faker->uuid(),
            'prospectos_ids' => [1, 2, 3],
            'fecha_inicio_programada' => now(),
            'estado' => 'pending',
            'config' => [],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'pending',
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'in_progress',
            'fecha_inicio_real' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'completed',
            'fecha_inicio_real' => now()->subHours(2),
            'fecha_fin' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'failed',
            'fecha_inicio_real' => now()->subHours(1),
            'error_message' => 'Error en la ejecución',
        ]);
    }
}
