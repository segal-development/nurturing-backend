<?php

namespace App\Imports;

use App\Models\Importacion;
use App\Models\Prospecto;
use App\Models\TipoProspecto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Validators\Failure;

class ProspectosImport implements OnEachRow, SkipsEmptyRows, SkipsOnFailure, WithBatchInserts, WithHeadingRow
{
    protected int $importacionId;

    protected int $registrosExitosos = 0;

    protected int $registrosFallidos = 0;

    protected array $errores = [];

    protected int $sinEmail = 0;

    protected int $sinTelefono = 0;

    public function __construct(int $importacionId)
    {
        $this->importacionId = $importacionId;
    }

    /**
     * Limpia y convierte el monto de deuda a entero.
     */
    protected function limpiarMontoDeuda(mixed $valor): int
    {
        if (is_numeric($valor)) {
            return (int) $valor;
        }

        $limpio = preg_replace('/[^0-9]/', '', (string) $valor);

        return (int) ($limpio ?: 0);
    }

    public function onRow(Row $row): void
    {
        $rowIndex = $row->getIndex();
        $rowData = $row->toArray();

        // Normalizar valores vacíos a null
        $rowData['email'] = ! empty($rowData['email']) ? $rowData['email'] : null;
        $rowData['telefono'] = ! empty($rowData['telefono']) ? $rowData['telefono'] : null;
        $rowData['rut'] = ! empty($rowData['rut']) ? $rowData['rut'] : null;
        $rowData['url_informe'] = ! empty($rowData['url_informe']) ? $rowData['url_informe'] : null;

        $validator = Validator::make($rowData, [
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'rut' => ['nullable', 'string', 'max:255'],
            'url_informe' => ['nullable', 'string', 'max:1000'],
            'monto_deuda' => ['nullable'],
        ]);

        if ($validator->fails()) {
            $this->registrosFallidos++;
            $this->errores[] = [
                'fila' => $rowIndex,
                'errores' => $validator->errors()->toArray(),
            ];

            return;
        }

        // Validar que al menos email o teléfono estén presentes
        if (empty($rowData['email']) && empty($rowData['telefono'])) {
            $this->registrosFallidos++;
            $this->errores[] = [
                'fila' => $rowIndex,
                'errores' => ['contacto' => 'Debe proporcionar al menos un email o teléfono'],
            ];

            return;
        }

        try {
            $montoDeuda = $this->limpiarMontoDeuda($rowData['monto_deuda'] ?? 0);

            $tipoProspecto = TipoProspecto::findByMonto((float) $montoDeuda);

            if (! $tipoProspecto) {
                $this->registrosFallidos++;
                $this->errores[] = [
                    'fila' => $rowIndex,
                    'errores' => ['monto_deuda' => 'No se encontró un tipo de prospecto para el monto: $'.number_format($montoDeuda, 0, ',', '.')],
                ];

                return;
            }

            // Buscar prospecto existente por email o teléfono
            $existente = null;
            if (! empty($rowData['email'])) {
                $existente = Prospecto::query()->where('email', $rowData['email'])->first();
            }

            if (! $existente && ! empty($rowData['telefono'])) {
                $existente = Prospecto::query()->where('telefono', $rowData['telefono'])->first();
            }

            // Contar registros sin email o teléfono
            if (empty($rowData['email'])) {
                $this->sinEmail++;
            }
            if (empty($rowData['telefono'])) {
                $this->sinTelefono++;
            }

            if ($existente) {
                $nuevoTipoProspecto = TipoProspecto::findByMonto((float) $montoDeuda);

                $updateData = [
                    'nombre' => $rowData['nombre'],
                    'monto_deuda' => $montoDeuda,
                    'tipo_prospecto_id' => $nuevoTipoProspecto?->id ?? $existente->tipo_prospecto_id,
                    'fecha_ultimo_contacto' => now(),
                    'metadata' => array_merge(
                        $existente->metadata ?? [],
                        ['ultima_actualizacion_importacion' => $this->importacionId]
                    ),
                ];

                // Solo actualizar email si viene en la fila
                if (! empty($rowData['email'])) {
                    $updateData['email'] = $rowData['email'];
                }

                // Solo actualizar teléfono si viene en la fila
                if (! empty($rowData['telefono'])) {
                    $updateData['telefono'] = $rowData['telefono'];
                }

                // Solo actualizar RUT si viene en la fila
                if (! empty($rowData['rut'])) {
                    $updateData['rut'] = $rowData['rut'];
                }

                // Solo actualizar URL informe si viene en la fila
                if (! empty($rowData['url_informe'])) {
                    $updateData['url_informe'] = $rowData['url_informe'];
                }

                $existente->update($updateData);
            } else {
                Prospecto::create([
                    'importacion_id' => $this->importacionId,
                    'nombre' => $rowData['nombre'],
                    'rut' => $rowData['rut'],
                    'email' => $rowData['email'],
                    'telefono' => $rowData['telefono'],
                    'url_informe' => $rowData['url_informe'],
                    'tipo_prospecto_id' => $tipoProspecto->id,
                    'estado' => 'activo',
                    'monto_deuda' => $montoDeuda,
                    'fila_excel' => $rowIndex,
                    'metadata' => ['importado_en' => now()->toISOString()],
                ]);
            }

            $this->registrosExitosos++;
        } catch (\Exception $e) {
            $this->registrosFallidos++;
            $this->errores[] = [
                'fila' => $rowIndex,
                'errores' => ['general' => $e->getMessage()],
            ];
            Log::error('Error al importar prospecto', [
                'fila' => $rowIndex,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onFailure(Failure ...$failures): void
    {
        foreach ($failures as $failure) {
            $this->registrosFallidos++;
            $this->errores[] = [
                'fila' => $failure->row(),
                'errores' => $failure->errors(),
            ];
        }
    }

    public function batchSize(): int
    {
        return 500;
    }

    public function getRegistrosExitosos(): int
    {
        return $this->registrosExitosos;
    }

    public function getRegistrosFallidos(): int
    {
        return $this->registrosFallidos;
    }

    public function getErrores(): array
    {
        return $this->errores;
    }

    public function getSinEmail(): int
    {
        return $this->sinEmail;
    }

    public function getSinTelefono(): int
    {
        return $this->sinTelefono;
    }

    public function actualizarImportacion(): void
    {
        $importacion = Importacion::find($this->importacionId);

        if ($importacion) {
            $importacion->update([
                'total_registros' => $this->registrosExitosos + $this->registrosFallidos,
                'registros_exitosos' => $this->registrosExitosos,
                'registros_fallidos' => $this->registrosFallidos,
                'estado' => $this->registrosFallidos > 0 && $this->registrosExitosos === 0 ? 'fallido' : 'completado',
                'metadata' => array_merge(
                    $importacion->metadata ?? [],
                    [
                        'errores' => $this->errores,
                        'registros_sin_email' => $this->sinEmail,
                        'registros_sin_telefono' => $this->sinTelefono,
                    ]
                ),
            ]);
        }
    }
}
