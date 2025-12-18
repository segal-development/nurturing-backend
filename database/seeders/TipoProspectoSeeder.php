<?php

namespace Database\Seeders;

use App\Models\TipoProspecto;
use Illuminate\Database\Seeder;

class TipoProspectoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tipos = [
            [
                'nombre' => 'Deuda Baja',
                'descripcion' => 'Prospectos con deuda entre $0 y $699,999 CLP',
                'monto_min' => 0,
                'monto_max' => 699999.99,
                'orden' => 1,
                'activo' => true,
            ],
            [
                'nombre' => 'Deuda Media',
                'descripcion' => 'Prospectos con deuda entre $700,000 y $1,499,999 CLP',
                'monto_min' => 700000,
                'monto_max' => 1499999.99,
                'orden' => 2,
                'activo' => true,
            ],
            [
                'nombre' => 'Deuda Alta',
                'descripcion' => 'Prospectos con deuda desde $1,500,000 CLP en adelante',
                'monto_min' => 1500000,
                'monto_max' => null,
                'orden' => 3,
                'activo' => true,
            ],
        ];

        foreach ($tipos as $tipo) {
            TipoProspecto::updateOrCreate(
                ['nombre' => $tipo['nombre']],
                $tipo
            );
        }
    }
}
