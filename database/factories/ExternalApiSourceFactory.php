<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExternalApiSource>
 */
class ExternalApiSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'display_name' => fake()->company(),
            'endpoint_url' => fake()->url(),
            'auth_type' => 'none',
            'auth_token' => null,
            'headers' => [],
            'field_mapping' => [
                'nombre' => 'nombre',
                'rut' => 'rut',
                'email' => 'email',
                'telefono' => 'telefono',
            ],
            'sync_filters' => [],
            'sync_frequency' => 'daily',
            'lote_prefix' => strtoupper(fake()->lexify('???')),
            'is_active' => true,
        ];
    }

    /**
     * Source inactivo.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Source tipo Sysgal.
     */
    public function sysgal(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'sysgal_'.fake()->word(),
            'display_name' => 'Sysgal - '.fake()->word(),
            'endpoint_url' => 'https://sysgal.segal.cl/defensoria/Servicio/ProspectosNoAgendados',
            'lote_prefix' => 'SYSGAL',
            'field_mapping' => [
                'nombre' => 'Nombre',
                'rut' => 'Rut',
                'email' => 'Email',
                'telefono' => 'Telefono',
                'monto_deuda' => 'TotalDeuda',
            ],
            'sync_filters' => [
                'dias_atras' => 7,
                'unificar_lotes' => true,
                'lote_global' => 'SYSGAL',
            ],
        ]);
    }
}
