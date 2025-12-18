<?php

namespace Database\Factories;

use App\Models\Plantilla;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlantillaFactory extends Factory
{
    protected $model = Plantilla::class;

    public function definition(): array
    {
        $tipo = $this->faker->randomElement(['sms', 'email']);

        return [
            'nombre' => $this->faker->words(3, true),
            'descripcion' => $this->faker->sentence(),
            'tipo' => $tipo,
            'contenido' => $tipo === 'sms' ? $this->faker->text(150) : null,
            'asunto' => $tipo === 'email' ? $this->faker->sentence() : null,
            'componentes' => $tipo === 'email' ? $this->generarComponentesEmail() : null,
            'activo' => $this->faker->boolean(80),
        ];
    }

    public function sms(): static
    {
        return $this->state(fn (array $attributes) => [
            'tipo' => 'sms',
            'contenido' => $this->faker->text(150),
            'asunto' => null,
            'componentes' => null,
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'tipo' => 'email',
            'contenido' => null,
            'asunto' => $this->faker->sentence(),
            'componentes' => $this->generarComponentesEmail(),
        ]);
    }

    public function activa(): static
    {
        return $this->state(fn (array $attributes) => [
            'activo' => true,
        ]);
    }

    public function inactiva(): static
    {
        return $this->state(fn (array $attributes) => [
            'activo' => false,
        ]);
    }

    private function generarComponentesEmail(): array
    {
        return [
            [
                'tipo' => 'logo',
                'url' => 'https://via.placeholder.com/150x60',
                'altura' => 60,
                'alineacion' => 'center',
            ],
            [
                'tipo' => 'texto',
                'contenido' => $this->faker->paragraph(),
                'alineacion' => 'left',
                'tamano' => 14,
                'color' => '#000000',
            ],
            [
                'tipo' => 'boton',
                'texto' => 'Ver más',
                'url' => 'https://example.com',
                'color_fondo' => '#007bff',
                'color_texto' => '#ffffff',
                'alineacion' => 'center',
            ],
            [
                'tipo' => 'separador',
                'color' => '#cccccc',
                'altura' => 1,
            ],
            [
                'tipo' => 'footer',
                'contenido' => $this->faker->company()."\n".$this->faker->address(),
                'color' => '#666666',
            ],
        ];
    }
}
