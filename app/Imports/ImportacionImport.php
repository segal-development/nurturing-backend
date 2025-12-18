<?php

namespace App\Imports;

use App\Models\Importacion;
use Maatwebsite\Excel\Concerns\ToModel;

class ImportacionImport implements ToModel
{
    /**
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        return new Importacion([
            'nombre_archivo' => \PhpO,
            'ruta_archivo' => $row[1],
            'origen' => $row[2],
            'total_registros' => $row[3],
            'registros_exitosos' => $row[4],
            'registros_fallidos' => $row[5],
            'user_id' => $row[6],
            'estado' => $row[7],
            'fecha_importacion' => \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row[8]),
            'metadata' => isset($row[9]) ? json_decode($row[9], true) : null,
        ]);
    }
}
